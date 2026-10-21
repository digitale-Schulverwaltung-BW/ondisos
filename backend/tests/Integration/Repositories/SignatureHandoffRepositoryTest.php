<?php
declare(strict_types=1);

namespace Tests\Integration\Repositories;

use App\Config\Database;
use App\Config\TenantContext;
use App\Repositories\SignatureHandoffRepository;
use PHPUnit\Framework\TestCase;

/**
 * Signature handoffs are scoped to the tenant: a session id of another school is not found,
 * cannot be signed, picked up or counted. Single use relies on the conditional UPDATE/DELETE.
 *
 * Requires: test DB with the signature_handoffs table (migrate.php or schema.sql).
 *
 * @group integration
 */
class SignatureHandoffRepositoryTest extends TestCase
{
    private const NOW = 1_800_000_000;

    private \mysqli $db;
    private SignatureHandoffRepository $repo;
    private int $tenantAId;
    private int $tenantBId;
    private string $id;

    protected function setUp(): void
    {
        parent::setUp();
        TenantContext::reset();

        $this->db = Database::getConnection();
        $this->repo = new SignatureHandoffRepository($this->db);

        $this->db->query(
            "INSERT INTO tenants (name, api_secret, slug, active) VALUES ('Sig Tenant A', 'secret-a-test', 'sig-tenant-a', 1)"
        );
        $this->tenantAId = (int)$this->db->insert_id;
        $this->db->query(
            "INSERT INTO tenants (name, api_secret, slug, active) VALUES ('Sig Tenant B', 'secret-b-test', 'sig-tenant-b', 1)"
        );
        $this->tenantBId = (int)$this->db->insert_id;

        $this->id = bin2hex(random_bytes(16));
        TenantContext::initialize($this->tenantAId);
        $this->repo->insert([
            'id' => $this->id,
            'form_key' => 'bs',
            'field_name' => 'unterschrift',
            'field_title' => 'Unterschrift',
            'width' => 300,
            'height' => 200,
            'write_hash' => hash('sha256', 'w'),
            'read_hash' => hash('sha256', 'r'),
            'created_at' => self::NOW,
            'expires_at' => self::NOW + 600,
        ]);
        TenantContext::reset();
    }

    protected function tearDown(): void
    {
        $stmt = $this->db->prepare('DELETE FROM tenants WHERE id IN (?, ?)');
        $stmt->bind_param('ii', $this->tenantAId, $this->tenantBId);
        $stmt->execute();
        $stmt->close();

        TenantContext::reset();
        parent::tearDown();
    }

    public function testOwnTenantFindsSession(): void
    {
        TenantContext::initialize($this->tenantAId);

        $row = $this->repo->find($this->id);

        $this->assertNotNull($row);
        $this->assertSame('open', $row['status']);
        $this->assertSame(self::NOW + 600, $row['expires_at']);
    }

    public function testOtherTenantCannotSeeOrChangeSession(): void
    {
        TenantContext::initialize($this->tenantBId);

        $this->assertNull($this->repo->find($this->id));
        $this->assertSame(0, $this->repo->countOpen(self::NOW));
        $this->assertFalse($this->repo->markScanned($this->id, self::NOW));
        $this->assertFalse($this->repo->storeSignature($this->id, 'data:image/png;base64,AAAA', self::NOW));
        $this->assertFalse($this->repo->delete($this->id));
        $this->assertSame(0, $this->repo->deleteExpired(self::NOW + 10_000));

        TenantContext::reset();
        TenantContext::initialize($this->tenantAId);
        $this->assertSame('open', $this->repo->find($this->id)['status']);
    }

    public function testSignatureCanBeStoredOnlyOnce(): void
    {
        TenantContext::initialize($this->tenantAId);

        $this->assertTrue($this->repo->markScanned($this->id, self::NOW));
        $this->assertTrue($this->repo->storeSignature($this->id, 'data:image/png;base64,AAAA', self::NOW));
        $this->assertFalse($this->repo->storeSignature($this->id, 'data:image/png;base64,BBBB', self::NOW));
        $this->assertSame('data:image/png;base64,AAAA', $this->repo->find($this->id)['png']);
    }

    public function testExpiredSessionCannotBeSigned(): void
    {
        TenantContext::initialize($this->tenantAId);

        $this->assertFalse($this->repo->storeSignature($this->id, 'data:image/png;base64,AAAA', self::NOW + 600));
    }

    public function testDeleteSucceedsOnlyOnce(): void
    {
        TenantContext::initialize($this->tenantAId);

        $this->assertTrue($this->repo->delete($this->id));
        $this->assertFalse($this->repo->delete($this->id));
    }

    public function testDeleteExpiredRemovesOnlyExpired(): void
    {
        TenantContext::initialize($this->tenantAId);

        $this->assertSame(0, $this->repo->deleteExpired(self::NOW + 599));
        $this->assertSame(1, $this->repo->countOpen(self::NOW + 599));
        $this->assertSame(1, $this->repo->deleteExpired(self::NOW + 600));
    }
}
