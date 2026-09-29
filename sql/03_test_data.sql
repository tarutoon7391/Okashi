-- 03_test_data.sql
-- お土産屋さん用 発注管理システム　テストデータ（担当E）
-- 01 → 02 を流した直後の空のDBに流す（伝票Noを 1 から決め打ちしているため）
-- 日付は CURDATE() からの相対。いつ流しても「当月」前後に入る（月初に流すと一部が前月になる）
--
-- 入っているもの
--   発注  ：確定済み4伝票（うち1つは取消のマイナス伝票：質問No.3）、未確定2伝票
--   納品  ：確定済み3伝票（一部納品あり）、未確定1伝票
--   返品  ：確定済み1伝票・未確定1伝票（納品のマイナス伝票 is_return=1：質問No.4）
--   売上  ：確定済み2日分（訂正のマイナス行を含む）、未確定1日分
--   棚卸  ：差異1件
--   在庫  ：上の確定済み伝票に合わせた数（最後にまとめて UPDATE）
--
-- 確認の目安（このデータを流した直後）
--   未納品一覧 … No.2-3 マンゴーアイス 残4、No.3-2 八ツ橋 残5
--                （No.1-3 生チョコは未確定の納品伝票No.5で残りの2を入力済みなので出ない）
--   在庫      … P0001=5 P0003=10 P0004=6 P0005=7 P0006=3 P0007=6 P0008=15 P0010=5（他は0）

USE omiyage_order;

-- ---------------------------------------------------------------
-- 発注伝票
-- ---------------------------------------------------------------
INSERT INTO t_order (order_no, order_date, supplier_code, is_confirmed, confirmed_at, confirmed_by, mailed_at, created_by, updated_by) VALUES
    (1, CURDATE() - INTERVAL 10 DAY, 'S001', 1, NOW() - INTERVAL 10 DAY, 'OP001', NOW() - INTERVAL 10 DAY, 'OP002', 'OP002'),
    (2, CURDATE() - INTERVAL 10 DAY, 'S002', 1, NOW() - INTERVAL 10 DAY, 'OP001', NOW() - INTERVAL 10 DAY, 'OP002', 'OP002'),
    (3, CURDATE() - INTERVAL 9 DAY,  'S003', 1, NOW() - INTERVAL 9 DAY,  'OP001', NULL,                    'OP003', 'OP003'),
    (4, CURDATE() - INTERVAL 8 DAY,  'S001', 1, NOW() - INTERVAL 8 DAY,  'OP001', NOW() - INTERVAL 8 DAY,  'OP002', 'OP002'),
    (5, CURDATE() - INTERVAL 2 DAY,  'S002', 0, NULL, NULL, NULL, 'OP002', 'OP002'),
    (6, CURDATE() - INTERVAL 1 DAY,  'S001', 0, NULL, NULL, NULL, 'OP003', 'OP003');

INSERT INTO t_order_detail (order_no, line_no, product_code, order_qty, contract_price, amount, memo, ref_order_no, ref_line_no, created_by, updated_by) VALUES
    (1, 1, 'P0001', 10,  650,  6500, NULL,                 NULL, NULL, 'OP002', 'OP002'),
    (1, 2, 'P0003', 20,  280,  5600, NULL,                 NULL, NULL, 'OP002', 'OP002'),
    (1, 3, 'P0006',  5,  800,  4000, '要冷蔵で配送',        NULL, NULL, 'OP002', 'OP002'),
    (2, 1, 'P0004', 12,  520,  6240, NULL,                 NULL, NULL, 'OP002', 'OP002'),
    (2, 2, 'P0005', 10,  430,  4300, NULL,                 NULL, NULL, 'OP002', 'OP002'),
    (2, 3, 'P0008', 24,  220,  5280, '冷凍便',              NULL, NULL, 'OP002', 'OP002'),
    (3, 1, 'P0007',  6,  600,  3600, NULL,                 NULL, NULL, 'OP003', 'OP003'),
    (3, 2, 'P0010', 15,  400,  6000, NULL,                 NULL, NULL, 'OP003', 'OP003'),
    (4, 1, 'P0003', -5,  280, -1400, '入れすぎのため取消',  1,    2,    'OP002', 'OP002'),
    (5, 1, 'P0009',  4,  980,  3920, NULL,                 NULL, NULL, 'OP002', 'OP002'),
    (5, 2, 'P0005',  5,  430,  2150, NULL,                 NULL, NULL, 'OP002', 'OP002'),
    (6, 1, 'P0002',  6, 1250,  7500, '年末用',              NULL, NULL, 'OP003', 'OP003');

-- ---------------------------------------------------------------
-- 納品伝票（返品は is_return=1 のマイナス伝票）
-- ---------------------------------------------------------------
INSERT INTO t_delivery (delivery_no, delivery_date, supplier_code, is_return, is_confirmed, confirmed_at, confirmed_by, created_by, updated_by) VALUES
    (1, CURDATE() - INTERVAL 7 DAY, 'S001', 0, 1, NOW() - INTERVAL 7 DAY, 'OP002', 'OP002', 'OP002'),
    (2, CURDATE() - INTERVAL 6 DAY, 'S002', 0, 1, NOW() - INTERVAL 6 DAY, 'OP002', 'OP002', 'OP002'),
    (3, CURDATE() - INTERVAL 5 DAY, 'S003', 0, 1, NOW() - INTERVAL 5 DAY, 'OP003', 'OP003', 'OP003'),
    (4, CURDATE() - INTERVAL 4 DAY, 'S002', 1, 1, NOW() - INTERVAL 4 DAY, 'OP002', 'OP002', 'OP002'),
    (5, CURDATE() - INTERVAL 1 DAY, 'S001', 0, 0, NULL, NULL,             'OP002', 'OP002'),
    (6, CURDATE() - INTERVAL 1 DAY, 'S003', 1, 0, NULL, NULL,             'OP003', 'OP003');

INSERT INTO t_delivery_detail (delivery_no, line_no, order_no, order_line_no, product_code, delivery_qty, contract_price, amount, memo, created_by, updated_by) VALUES
    (1, 1, 1, 1, 'P0001', 10, 650,  6500, NULL,           'OP002', 'OP002'),
    (1, 2, 1, 2, 'P0003', 15, 280,  4200, NULL,           'OP002', 'OP002'),
    (1, 3, 1, 3, 'P0006',  3, 800,  2400, '残りは後日',    'OP002', 'OP002'),
    (2, 1, 2, 1, 'P0004', 12, 520,  6240, NULL,           'OP002', 'OP002'),
    (2, 2, 2, 2, 'P0005', 10, 430,  4300, NULL,           'OP002', 'OP002'),
    (2, 3, 2, 3, 'P0008', 20, 220,  4400, '4個欠品',       'OP002', 'OP002'),
    (3, 1, 3, 1, 'P0007',  6, 600,  3600, NULL,           'OP003', 'OP003'),
    (3, 2, 3, 2, 'P0010', 10, 400,  4000, NULL,           'OP003', 'OP003'),
    (4, 1, 2, 1, 'P0004', -2, 520, -1040, '箱つぶれ',      'OP002', 'OP002'),
    (5, 1, 1, 3, 'P0006',  2, 800,  1600, NULL,           'OP002', 'OP002'),
    (6, 1, 3, 1, 'P0007', -1, 600,  -600, '賞味期限間近', 'OP003', 'OP003');

-- ---------------------------------------------------------------
-- 売上（金額 = 定価 × 数量）
-- ---------------------------------------------------------------
INSERT INTO t_sales (sales_date, product_code, sales_qty, amount, is_confirmed, confirmed_at, confirmed_by, created_by, updated_by) VALUES
    (CURDATE() - INTERVAL 3 DAY, 'P0001',  3, 2940, 1, NOW() - INTERVAL 3 DAY, 'OP002', 'OP002', 'OP002'),
    (CURDATE() - INTERVAL 3 DAY, 'P0004',  4, 3200, 1, NOW() - INTERVAL 3 DAY, 'OP002', 'OP002', 'OP002'),
    (CURDATE() - INTERVAL 3 DAY, 'P0005',  2, 1400, 1, NOW() - INTERVAL 3 DAY, 'OP002', 'OP002', 'OP002'),
    (CURDATE() - INTERVAL 3 DAY, 'P0010',  5, 3250, 1, NOW() - INTERVAL 3 DAY, 'OP002', 'OP002', 'OP002'),
    (CURDATE() - INTERVAL 2 DAY, 'P0001',  2, 1960, 1, NOW() - INTERVAL 2 DAY, 'OP003', 'OP003', 'OP003'),
    (CURDATE() - INTERVAL 2 DAY, 'P0003',  6, 2700, 1, NOW() - INTERVAL 2 DAY, 'OP003', 'OP003', 'OP003'),
    (CURDATE() - INTERVAL 2 DAY, 'P0008',  5, 1900, 1, NOW() - INTERVAL 2 DAY, 'OP003', 'OP003', 'OP003'),
    (CURDATE() - INTERVAL 2 DAY, 'P0003', -1, -450, 1, NOW() - INTERVAL 2 DAY, 'OP003', 'OP003', 'OP003'),  -- 訂正行（質問No.8）
    (CURDATE() - INTERVAL 1 DAY, 'P0001',  1,  980, 0, NULL, NULL, 'OP002', 'OP002'),
    (CURDATE() - INTERVAL 1 DAY, 'P0007',  2, 1800, 0, NULL, NULL, 'OP002', 'OP002');

-- ---------------------------------------------------------------
-- 棚卸（差異があった商品だけ）
-- ---------------------------------------------------------------
INSERT INTO t_stocktaking (stocktaking_date, product_code, book_qty, actual_qty, diff_qty, reason, created_by, updated_by) VALUES
    (CURDATE() - INTERVAL 1 DAY, 'P0005', 8, 7, -1, '破損1個（廃棄）', 'OP001', 'OP001');

-- ---------------------------------------------------------------
-- 在庫（テストデータなので直接合わせる。画面からは必ず slip.php 経由）
--   = 確定済み納品（返品を含む）− 確定済み売上、棚卸した商品は実数
-- ---------------------------------------------------------------
UPDATE t_stock SET updated_by = 'OP001', stock_qty = CASE product_code
    WHEN 'P0001' THEN 5
    WHEN 'P0003' THEN 10
    WHEN 'P0004' THEN 6
    WHEN 'P0005' THEN 7
    WHEN 'P0006' THEN 3
    WHEN 'P0007' THEN 6
    WHEN 'P0008' THEN 15
    WHEN 'P0010' THEN 5
    ELSE 0 END;
