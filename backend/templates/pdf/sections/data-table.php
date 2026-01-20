<h2>Formulardaten</h2>

<?php if (empty($data)): ?>
    <p class="no-data">Keine Daten vorhanden</p>
<?php else: ?>
    <table class="data-table">
        <thead>
            <tr>
                <th style="width: 35%;">Feld</th>
                <th>Wert</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($data as $key => $value): ?>
                <tr>
                    <th><?= htmlspecialchars($formatter::humanizeKey($key)) ?></th>
                    <td><?= nl2br(htmlspecialchars($formatter::formatValue($value))) ?></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
<?php endif; ?>
