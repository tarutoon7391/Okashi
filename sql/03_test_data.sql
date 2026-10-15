-- 03_test_data.sql
-- お土産屋さん用 発注管理システム　テストデータ（担当E）
-- 01 → 02 を流した直後の空のDBに流す（伝票Noを 1 から決め打ちしているため）
-- 日付は CURDATE() からの相対。いつ流しても「当月」前後に入る（月初に流すと一部が前月になる）
-- 2026-10-15：マスタ差し替えに合わせて商品・卸業者・操作者のコードを置き換え（筋書きは同じ）
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
--   未納品一覧 … No.2-3 R009 味の明太子 レギュラー 120g 残4、No.3-2 F001 宇都宮餃子 24個入 残5
--                （No.1-3 赤福餅は未確定の納品伝票No.5で残りの2を入力済みなので出ない）
--   在庫      … F001=5 F005=6 K001=5 K024=10 K032=3 R001=6 R007=7 R009=15（他は0）

USE omiyage_order;

-- ---------------------------------------------------------------
-- 発注伝票
-- ---------------------------------------------------------------
INSERT INTO t_order (order_no, order_date, supplier_code, is_confirmed, confirmed_at, confirmed_by, mailed_at, created_by, updated_by) VALUES
    (1, CURDATE() - INTERVAL 10 DAY, 'W003', 1, NOW() - INTERVAL 10 DAY, 'U001', NOW() - INTERVAL 10 DAY, 'U002', 'U002'),
    (2, CURDATE() - INTERVAL 10 DAY, 'W001', 1, NOW() - INTERVAL 10 DAY, 'U001', NOW() - INTERVAL 10 DAY, 'U002', 'U002'),
    (3, CURDATE() - INTERVAL 9 DAY, 'W002', 1, NOW() - INTERVAL 9 DAY, 'U001', NULL, 'U003', 'U003'),
    (4, CURDATE() - INTERVAL 8 DAY, 'W003', 1, NOW() - INTERVAL 8 DAY, 'U001', NOW() - INTERVAL 8 DAY, 'U002', 'U002'),
    (5, CURDATE() - INTERVAL 2 DAY, 'W001', 0, NULL, NULL, NULL, 'U002', 'U002'),
    (6, CURDATE() - INTERVAL 1 DAY, 'W003', 0, NULL, NULL, NULL, 'U003', 'U003');

INSERT INTO t_order_detail (order_no, line_no, product_code, order_qty, contract_price, amount, memo, ref_order_no, ref_line_no, created_by, updated_by) VALUES
    (1, 1, 'K001',  10,   620,   6200, NULL, NULL, NULL, 'U002', 'U002'),
    (1, 2, 'K024',  20,   287,   5740, NULL, NULL, NULL, 'U002', 'U002'),
    (1, 3, 'K032',   5,   517,   2585, '賞味期限が短いので当日便で', NULL, NULL, 'U002', 'U002'),
    (2, 1, 'R001',  12,   520,   6240, NULL, NULL, NULL, 'U002', 'U002'),
    (2, 2, 'R007',  10,   506,   5060, NULL, NULL, NULL, 'U002', 'U002'),
    (2, 3, 'R009',  24,   975,  23400, '冷蔵便', NULL, NULL, 'U002', 'U002'),
    (3, 1, 'F005',   6,   451,   2706, NULL, NULL, NULL, 'U003', 'U003'),
    (3, 2, 'F001',  15,   903,  13545, NULL, NULL, NULL, 'U003', 'U003'),
    (4, 1, 'K024',  -5,   287,  -1435, '入れすぎのため取消', 1, 2, 'U002', 'U002'),
    (5, 1, 'R011',   4,   780,   3120, NULL, NULL, NULL, 'U002', 'U002'),
    (5, 2, 'R007',   5,   506,   2530, NULL, NULL, NULL, 'U002', 'U002'),
    (6, 1, 'K013',   6,   744,   4464, '年末用', NULL, NULL, 'U003', 'U003');

-- ---------------------------------------------------------------
-- 納品伝票（返品は is_return=1 のマイナス伝票）
-- ---------------------------------------------------------------
INSERT INTO t_delivery (delivery_no, delivery_date, supplier_code, is_return, is_confirmed, confirmed_at, confirmed_by, created_by, updated_by) VALUES
    (1, CURDATE() - INTERVAL 7 DAY, 'W003', 0, 1, NOW() - INTERVAL 7 DAY, 'U002', 'U002', 'U002'),
    (2, CURDATE() - INTERVAL 6 DAY, 'W001', 0, 1, NOW() - INTERVAL 6 DAY, 'U002', 'U002', 'U002'),
    (3, CURDATE() - INTERVAL 5 DAY, 'W002', 0, 1, NOW() - INTERVAL 5 DAY, 'U003', 'U003', 'U003'),
    (4, CURDATE() - INTERVAL 4 DAY, 'W001', 1, 1, NOW() - INTERVAL 4 DAY, 'U002', 'U002', 'U002'),
    (5, CURDATE() - INTERVAL 1 DAY, 'W003', 0, 0, NULL, NULL, 'U002', 'U002'),
    (6, CURDATE() - INTERVAL 1 DAY, 'W002', 1, 0, NULL, NULL, 'U003', 'U003');

INSERT INTO t_delivery_detail (delivery_no, line_no, order_no, order_line_no, product_code, delivery_qty, contract_price, amount, memo, created_by, updated_by) VALUES
    (1, 1, 1, 1, 'K001',  10,   620,   6200, NULL, 'U002', 'U002'),
    (1, 2, 1, 2, 'K024',  15,   287,   4305, NULL, 'U002', 'U002'),
    (1, 3, 1, 3, 'K032',   3,   517,   1551, '残りは後日', 'U002', 'U002'),
    (2, 1, 2, 1, 'R001',  12,   520,   6240, NULL, 'U002', 'U002'),
    (2, 2, 2, 2, 'R007',  10,   506,   5060, NULL, 'U002', 'U002'),
    (2, 3, 2, 3, 'R009',  20,   975,  19500, '4個欠品', 'U002', 'U002'),
    (3, 1, 3, 1, 'F005',   6,   451,   2706, NULL, 'U003', 'U003'),
    (3, 2, 3, 2, 'F001',  10,   903,   9030, NULL, 'U003', 'U003'),
    (4, 1, 2, 1, 'R001',  -2,   520,  -1040, '箱つぶれ', 'U002', 'U002'),
    (5, 1, 1, 3, 'K032',   2,   517,   1034, NULL, 'U002', 'U002'),
    (6, 1, 3, 1, 'F005',  -1,   451,   -451, '賞味期限間近', 'U003', 'U003');

-- ---------------------------------------------------------------
-- 売上（金額 = 定価 × 数量）
-- ---------------------------------------------------------------
INSERT INTO t_sales (sales_date, product_code, sales_qty, amount, is_confirmed, confirmed_at, confirmed_by, created_by, updated_by) VALUES
    (CURDATE() - INTERVAL 3 DAY, 'K001',   3,   3240, 1, NOW() - INTERVAL 3 DAY, 'U002', 'U002', 'U002'),
    (CURDATE() - INTERVAL 3 DAY, 'R001',   4,   3456, 1, NOW() - INTERVAL 3 DAY, 'U002', 'U002', 'U002'),
    (CURDATE() - INTERVAL 3 DAY, 'R007',   2,   1680, 1, NOW() - INTERVAL 3 DAY, 'U002', 'U002', 'U002'),
    (CURDATE() - INTERVAL 3 DAY, 'F001',   5,   7500, 1, NOW() - INTERVAL 3 DAY, 'U002', 'U002', 'U002'),
    (CURDATE() - INTERVAL 2 DAY, 'K001',   2,   2160, 1, NOW() - INTERVAL 2 DAY, 'U003', 'U003', 'U003'),
    (CURDATE() - INTERVAL 2 DAY, 'K024',   6,   3000, 1, NOW() - INTERVAL 2 DAY, 'U003', 'U003', 'U003'),
    (CURDATE() - INTERVAL 2 DAY, 'R009',   5,   8100, 1, NOW() - INTERVAL 2 DAY, 'U003', 'U003', 'U003'),
    (CURDATE() - INTERVAL 2 DAY, 'K024',  -1,   -500, 1, NOW() - INTERVAL 2 DAY, 'U003', 'U003', 'U003'),  -- 訂正行（質問No.8）
    (CURDATE() - INTERVAL 1 DAY, 'K001',   1,   1080, 0, NULL, NULL, 'U002', 'U002'),
    (CURDATE() - INTERVAL 1 DAY, 'F005',   2,   1500, 0, NULL, NULL, 'U002', 'U002');

-- ---------------------------------------------------------------
-- 棚卸（差異があった商品だけ）
-- ---------------------------------------------------------------
INSERT INTO t_stocktaking (stocktaking_date, product_code, book_qty, actual_qty, diff_qty, reason, created_by, updated_by) VALUES
    (CURDATE() - INTERVAL 1 DAY, 'R007', 8, 7, -1, '破損1個（廃棄）', 'U001', 'U001');

-- ---------------------------------------------------------------
-- 在庫（テストデータなので直接合わせる。画面からは必ず slip.php 経由）
--   = 確定済み納品（返品を含む）− 確定済み売上、棚卸した商品は実数
-- ---------------------------------------------------------------
UPDATE t_stock SET updated_by = 'U001', stock_qty = CASE product_code
    WHEN 'F001' THEN 5
    WHEN 'F005' THEN 6
    WHEN 'K001' THEN 5
    WHEN 'K024' THEN 10
    WHEN 'K032' THEN 3
    WHEN 'R001' THEN 6
    WHEN 'R007' THEN 7
    WHEN 'R009' THEN 15
    ELSE 0 END;
