<h2>Formulardaten</h2>

<?php if (empty($data)): ?>
    <p class="no-data">Keine Daten vorhanden</p>
<?php else: ?>
    <?php
    // Gruppiere Daten in Paare für zweispaltiges Layout
    $dataArray = is_array($data) ? $data : iterator_to_array($data);
    $pairs = array_chunk($dataArray, 2, true);
    ?>
    <table class="data-table">
        <tbody>
            <?php foreach ($pairs as $pair): ?>
                <?php
                $items = array_values($pair);
                $keys = array_keys($pair);
                $isLastOdd = (count($items) === 1);
                ?>
                <tr>
                    <!-- Erstes Feld-Wert-Paar -->
                    <th><?= htmlspecialchars($formatter::humanizeKey($keys[0])) ?></th>
                    <td<?= $isLastOdd ? ' colspan="3"' : '' ?>><?php if ($formatter::isImageDataUri($items[0])): ?><img src="<?= htmlspecialchars($items[0]) ?>" class="signature" alt=""><?php else: ?><?= nl2br(htmlspecialchars($formatter::formatValue($items[0]))) ?><?php endif; ?></td>

                    <!-- Zweites Feld-Wert-Paar (nur bei gerader Anzahl) -->
                    <?php if (!$isLastOdd): ?>
                        <th><?= htmlspecialchars($formatter::humanizeKey($keys[1])) ?></th>
                        <td><?php if ($formatter::isImageDataUri($items[1])): ?><img src="<?= htmlspecialchars($items[1]) ?>" class="signature" alt=""><?php else: ?><?= nl2br(htmlspecialchars($formatter::formatValue($items[1]))) ?><?php endif; ?></td>
                    <?php endif; ?>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
<?php endif; ?>
