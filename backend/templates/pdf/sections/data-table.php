<?php if (!$withHeadings): ?>
<h2>Formulardaten</h2>
<?php endif; ?>

<?php if (empty($data)): ?>
    <p class="no-data">Keine Daten vorhanden</p>
<?php else: ?>
    <table class="data-table">
        <tbody>
            <?php foreach ($groups as $group): ?>
                <?php if ($group['title'] !== ''): ?>
                    <tr class="page-heading">
                        <th colspan="4"><?= htmlspecialchars($group['title']) ?></th>
                    </tr>
                <?php endif; ?>
                <?php
                // Gruppiere Daten in Paare für zweispaltiges Layout (je Seite neu)
                $pairs = array_chunk($group['fields'], 2, true);
                ?>
                <?php foreach ($pairs as $pair): ?>
                    <?php
                    $items = array_values($pair);
                    $keys = array_keys($pair);
                    $isLastOdd = (count($items) === 1);
                    ?>
                    <tr>
                        <!-- Erstes Feld-Wert-Paar -->
                        <th><?= htmlspecialchars($formatter::fieldLabel((string)$keys[0], $fieldTypes)) ?></th>
                        <td<?= $isLastOdd ? ' colspan="3"' : '' ?>><?php if ($formatter::isImageDataUri($items[0])): ?><img src="<?= htmlspecialchars($items[0]) ?>" class="signature" alt=""><?php else: ?><?= nl2br(htmlspecialchars($formatter::formatValue($items[0]))) ?><?php endif; ?></td>

                        <!-- Zweites Feld-Wert-Paar (nur bei gerader Anzahl) -->
                        <?php if (!$isLastOdd): ?>
                            <th><?= htmlspecialchars($formatter::fieldLabel((string)$keys[1], $fieldTypes)) ?></th>
                            <td><?php if ($formatter::isImageDataUri($items[1])): ?><img src="<?= htmlspecialchars($items[1]) ?>" class="signature" alt=""><?php else: ?><?= nl2br(htmlspecialchars($formatter::formatValue($items[1]))) ?><?php endif; ?></td>
                        <?php endif; ?>
                    </tr>
                <?php endforeach; ?>
            <?php endforeach; ?>
        </tbody>
    </table>
<?php endif; ?>
