<?php
// 古いデータの削除（データ保存期間：要件定義書 §4 非機能要件）（コマンドライン専用。ブラウザからは開けない）
//
//   「6年目終了時に古いデータ1年分を削除して5年分を保持する。
//     削除対象となるデータは、納品・返品処理が完了しているデータのみとする。」
//
//   php tools/purge_old_data.php                       … 確認だけ（DRY-RUN）。消える件数を表示する。何も消さない
//   php tools/purge_old_data.php --execute             … 実際に削除する（1トランザクション。途中で失敗したら全部元に戻す）
//   php tools/purge_old_data.php --before=2021-04-01   … 基準日を指定（この日より前の発注日の伝票が対象）
//   php tools/purge_old_data.php --verbose             … 対象の発注No・納品No も表示する
//
// ■ 基準日（--before を省略したとき）
//   年度は 4月始まり（4/1〜翌3/31）とし、「今年度の開始日の5年前の 4/1」を基準日にする。
//   例：2032/4/1〜2033/3/31 に実行 → 基準日 2027/4/1 → 2027年3月31日以前の伝票を削除し、
//       2027年度〜2031年度（5年分）＋今年度を残す。
//   年度が終わったら（4月の最初の営業日の前など）1回実行する想定。
//
// ■ 削除する条件（発注伝票単位。すべて満たすものだけ）
//   1. 発注が確定済み（is_confirmed = 1）で、発注日が基準日より前
//   2. 元明細（ref_order_no が NULL・数量プラス）の未納品数がすべて 0
//        未納品数 = 発注数 ＋ 取消明細の数量合計 − 確定済み納品（返品以外）の数量合計（04 の定義と同じ）
//   3. その発注を指す納品・返品伝票がすべて確定済みで、納品日が基準日より前
//   4. つながっている伝票も一緒に消せる
//        ・その発注の明細を取り消している取消明細（別の発注伝票にあることもある）の発注伝票
//        ・その発注にある取消明細が指している元明細の発注伝票
//        ・その発注を指す納品・返品伝票に入っている、ほかの発注伝票
//      がすべて削除対象になる（1つでも残るなら、つながっている伝票はまとめて残す）
//
// ■ 削除する順番（FK の子 → 親）
//   t_delivery_detail → t_delivery → t_order_detail（取消明細 → 元明細：自己参照FKのため）→ t_order
//
// ■ 削除しないもの
//   マスタ（m_*）・在庫（t_stock）・売上（t_sales）・棚卸（t_stocktaking）は対象外。
//   要件の削除対象は「納品・返品処理が完了しているデータ」のため。売上・棚卸の扱いは docs/運用手順書.md を参照。
//
// 実行前に必ずバックアップを取ること（tools/backup_db.sh）。DB_NAME=... を付けると別のDBを使える
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require_once __DIR__ . '/../common/db.php';

$args    = array_slice($argv, 1);
$execute = in_array('--execute', $args, true);
$verbose = in_array('--verbose', $args, true);
$before  = null;
foreach ($args as $a) {
    if (strncmp($a, '--before=', 9) === 0) {
        $before = substr($a, 9);
    } elseif (!in_array($a, ['--execute', '--verbose'], true)) {
        fwrite(STDERR, "[purge] 知らないオプションです：{$a}\n");
        exit(1);
    }
}
if ($before === null) {
    // 今年度の開始日（4/1）の5年前
    $y = (int)date('Y');
    if ((int)date('n') < 4) {
        $y--;
    }
    $before = sprintf('%04d-04-01', $y - 5);
}
$d = DateTime::createFromFormat('Y-m-d', $before);
if ($d === false || $d->format('Y-m-d') !== $before) {
    fwrite(STDERR, "[purge] --before は YYYY-MM-DD で指定してください：{$before}\n");
    exit(1);
}
if ($before > date('Y-m-d', strtotime('+1 day'))) {
    fwrite(STDERR, "[purge] 基準日が未来になっています：{$before}\n");
    exit(1);
}

$pdo = getDb();
echo '[purge] DB：' . DB_NAME . '　基準日：' . $before . '（発注日がこれより前の伝票が対象）　モード：'
    . ($execute ? '削除する（--execute）' : '確認だけ（DRY-RUN）') . "\n";

$tables = ['t_order', 't_order_detail', 't_delivery', 't_delivery_detail', 't_sales', 't_stocktaking'];

try {
    if ($execute) {
        $pdo->beginTransaction();
    }
    [$orderNos, $deliveryNos] = findPurgeTargets($pdo, $before, $execute);
    $counts = countTargets($pdo, $orderNos, $deliveryNos);

    echo "[purge] 削除対象の件数\n";
    foreach ($counts as $t => $n) {
        echo sprintf("  %-18s %6d 件\n", $t, $n);
    }
    if ($verbose) {
        echo '  発注No：' . ($orderNos ? implode(',', $orderNos) : 'なし') . "\n";
        echo '  納品No：' . ($deliveryNos ? implode(',', $deliveryNos) : 'なし') . "\n";
    }

    if (!$execute) {
        echo "[purge] DRY-RUN のため何も削除していません。削除するときは --execute を付けてください（先にバックアップ）\n";
        exit(0);
    }
    if (!$orderNos) {
        $pdo->rollBack();
        echo "[purge] 削除対象がないので終了します\n";
        exit(0);
    }

    // FK の子 → 親の順に削除
    $deleted = [];
    if ($deliveryNos) {
        $deleted['t_delivery_detail'] = deleteIn($pdo, 'DELETE FROM t_delivery_detail WHERE delivery_no IN (%s)', $deliveryNos);
        $deleted['t_delivery']        = deleteIn($pdo, 'DELETE FROM t_delivery WHERE delivery_no IN (%s)', $deliveryNos);
    }
    // 自己参照FK（取消明細 → 元明細）があるので、取消明細を先に消す
    $deleted['t_order_detail'] = deleteIn($pdo, 'DELETE FROM t_order_detail WHERE ref_order_no IS NOT NULL AND order_no IN (%s)', $orderNos)
                               + deleteIn($pdo, 'DELETE FROM t_order_detail WHERE order_no IN (%s)', $orderNos);
    $deleted['t_order']        = deleteIn($pdo, 'DELETE FROM t_order WHERE order_no IN (%s)', $orderNos);

    // 数えた件数と実際に消えた件数が違ったら（途中で誰かが更新した等）全部元に戻す
    foreach ($deleted as $t => $n) {
        if ($n !== $counts[$t]) {
            throw new RuntimeException("{$t} の削除件数が想定と違います（想定 {$counts[$t]} 件・実際 {$n} 件）");
        }
    }
    $pdo->commit();
    echo "[purge] 削除しました\n";
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    fwrite(STDERR, '[purge] 失敗したので元に戻しました：' . $e->getMessage() . "\n");
    exit(1);
}

echo "[purge] 削除後の件数\n";
foreach ($tables as $t) {
    echo sprintf("  %-18s %6d 件\n", $t, (int)$pdo->query("SELECT COUNT(*) FROM {$t}")->fetchColumn());
}
exit(0);

// 削除できる発注No・納品No を求める（条件はファイル先頭のコメント）
// $lock = true のときは対象候補の行をロックする（--execute 時。トランザクション内で呼ぶ）
function findPurgeTargets(PDO $pdo, string $before, bool $lock): array
{
    $forUpdate = $lock ? ' FOR UPDATE' : '';

    // 1. 確定済み・基準日より前の発注
    $st = $pdo->prepare('SELECT order_no FROM t_order WHERE is_confirmed = 1 AND order_date < :before ORDER BY order_no' . $forUpdate);
    $st->execute([':before' => $before]);
    $cand = array_fill_keys(array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN)), true);
    if (!$cand) {
        return [[], []];
    }

    // 全明細（取消明細は別の発注伝票から元明細を指すことがあるので全件読む）
    $lines = $pdo->query('SELECT order_no, line_no, order_qty, ref_order_no, ref_line_no FROM t_order_detail' . $forUpdate)->fetchAll();
    // 全納品明細（返品含む）と伝票の状態
    $dels = $pdo->query('SELECT dd.delivery_no, dd.order_no, dd.order_line_no, dd.delivery_qty,
                                d.is_return, d.is_confirmed, d.delivery_date
                           FROM t_delivery_detail dd JOIN t_delivery d ON d.delivery_no = dd.delivery_no' . $forUpdate)->fetchAll();

    $remain  = [];   // "発注No-行No" => 未納品数（元明細・数量プラスのみ）
    $links   = [];   // 発注No => [一緒に消す必要がある発注No => true]（取消明細のつながり）
    foreach ($lines as $l) {
        if ($l['ref_order_no'] === null) {
            if ((int)$l['order_qty'] > 0) {
                $remain[$l['order_no'] . '-' . $l['line_no']] = (int)$l['order_qty'];
            }
        }
    }
    foreach ($lines as $l) {
        if ($l['ref_order_no'] !== null) {
            $key = $l['ref_order_no'] . '-' . $l['ref_line_no'];
            if (isset($remain[$key])) {
                $remain[$key] += (int)$l['order_qty'];
            }
            // 取消明細の伝票と元明細の伝票はお互いに「一緒に消す」関係
            $links[(int)$l['order_no']][(int)$l['ref_order_no']]  = true;
            $links[(int)$l['ref_order_no']][(int)$l['order_no']]  = true;
        }
    }

    $orderDeliveries = [];   // 発注No => [納品No => true]
    $deliveryOrders  = [];   // 納品No => [発注No => true]
    $badOrders       = [];   // 条件3を満たさない発注No
    foreach ($dels as $d) {
        $o  = (int)$d['order_no'];
        $dn = (int)$d['delivery_no'];
        $orderDeliveries[$o][$dn] = true;
        $deliveryOrders[$dn][$o]  = true;
        if ((int)$d['is_confirmed'] !== 1 || $d['delivery_date'] >= $before) {
            $badOrders[$o] = true;   // 未確定の納品・返品がある／基準日以降の納品がある
        }
        if ((int)$d['is_confirmed'] === 1 && (int)$d['is_return'] === 0) {
            $key = $d['order_no'] . '-' . $d['order_line_no'];
            if (isset($remain[$key])) {
                $remain[$key] -= (int)$d['delivery_qty'];
            }
        }
    }
    // 2. 未納品が残っている発注
    foreach ($remain as $key => $qty) {
        if ($qty !== 0) {
            $badOrders[(int)explode('-', $key)[0]] = true;
        }
    }
    foreach (array_keys($badOrders) as $o) {
        unset($cand[$o]);
    }

    // 4. つながっている伝票が1つでも残るなら外す（外すと別の伝票が外れることがあるので、変わらなくなるまで繰り返す）
    do {
        $changed = false;
        foreach (array_keys($cand) as $o) {
            $need = array_keys($links[$o] ?? []);
            foreach (array_keys($orderDeliveries[$o] ?? []) as $dn) {
                $need = array_merge($need, array_keys($deliveryOrders[$dn]));
            }
            foreach ($need as $n) {
                if (!isset($cand[$n])) {
                    unset($cand[$o]);
                    $changed = true;
                    break;
                }
            }
        }
    } while ($changed);

    $orderNos = array_keys($cand);
    sort($orderNos);
    $deliveryNos = [];
    foreach ($orderNos as $o) {
        foreach (array_keys($orderDeliveries[$o] ?? []) as $dn) {
            $deliveryNos[$dn] = true;
        }
    }
    $deliveryNos = array_keys($deliveryNos);
    sort($deliveryNos);
    return [$orderNos, $deliveryNos];
}

// テーブルごとの削除件数（売上・棚卸は常に0：対象外）
function countTargets(PDO $pdo, array $orderNos, array $deliveryNos): array
{
    $count = function (string $sql, array $ids) use ($pdo): int {
        if (!$ids) {
            return 0;
        }
        $st = $pdo->prepare(sprintf($sql, implode(',', array_fill(0, count($ids), '?'))));
        $st->execute($ids);
        return (int)$st->fetchColumn();
    };
    return [
        't_order'           => $count('SELECT COUNT(*) FROM t_order WHERE order_no IN (%s)', $orderNos),
        't_order_detail'    => $count('SELECT COUNT(*) FROM t_order_detail WHERE order_no IN (%s)', $orderNos),
        't_delivery'        => $count('SELECT COUNT(*) FROM t_delivery WHERE delivery_no IN (%s)', $deliveryNos),
        't_delivery_detail' => $count('SELECT COUNT(*) FROM t_delivery_detail WHERE delivery_no IN (%s)', $deliveryNos),
    ];
}

// "... IN (%s)" の DELETE を実行して、消えた行数を返す
function deleteIn(PDO $pdo, string $sql, array $ids): int
{
    $st = $pdo->prepare(sprintf($sql, implode(',', array_fill(0, count($ids), '?'))));
    $st->execute($ids);
    return $st->rowCount();
}
