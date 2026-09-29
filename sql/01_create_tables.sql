-- 01_create_tables.sql
-- お土産屋さん用 発注管理システム　テーブル作成（テーブル定義書 版1.0 準拠）
-- 担当A　コマ3
-- ・作成順は FK の親 → 子
-- ・created_by / updated_by / confirmed_by には FK を付けない（04 ER概要）
-- ・金額・数量はすべて INT（税は扱わない：質問No.7）

CREATE DATABASE IF NOT EXISTS omiyage_order
    DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;
USE omiyage_order;

-- 卸業者マスタ
CREATE TABLE m_supplier (
    supplier_code   VARCHAR(10)  NOT NULL,
    supplier_name   VARCHAR(100) NOT NULL,
    office_address  VARCHAR(200) NULL,
    order_email     VARCHAR(100) NULL,                  -- 発注書PDFの送付先。NULLなら送信せず警告
    contact_name    VARCHAR(50)  NULL,
    memo            TEXT         NULL,
    is_deleted      TINYINT(1)   NOT NULL DEFAULT 0,
    created_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    created_by      VARCHAR(10)  NOT NULL,
    updated_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    updated_by      VARCHAR(10)  NOT NULL,
    PRIMARY KEY (supplier_code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- 商品マスタ（入数違いは別商品コード：質問No.1）
CREATE TABLE m_product (
    product_code    VARCHAR(13)  NOT NULL,
    product_name    VARCHAR(100) NOT NULL,
    product_kana    VARCHAR(100) NOT NULL,              -- 全角カタカナ。一覧はこの昇順
    spec            VARCHAR(50)  NULL,
    pack_qty        INT          NOT NULL DEFAULT 1,
    unit            VARCHAR(10)  NOT NULL DEFAULT '個',
    jan_code        VARCHAR(13)  NULL,
    list_price      INT          NOT NULL DEFAULT 0,
    maker_name      VARCHAR(100) NULL,
    supplier_code   VARCHAR(10)  NOT NULL,
    contract_price  INT          NOT NULL DEFAULT 0,
    photo_path      VARCHAR(255) NULL,
    memo            TEXT         NULL,
    storage_type    TINYINT      NOT NULL DEFAULT 0,    -- 0=常温 1=冷蔵 2=冷凍（質問No.12）
    shelf_life_days INT          NULL,
    is_deleted      TINYINT(1)   NOT NULL DEFAULT 0,
    created_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    created_by      VARCHAR(10)  NOT NULL,
    updated_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    updated_by      VARCHAR(10)  NOT NULL,
    PRIMARY KEY (product_code),
    FOREIGN KEY (supplier_code) REFERENCES m_supplier (supplier_code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- 操作者マスタ（email は二要素認証の送信先なので必須：質問No.10）
CREATE TABLE m_operator (
    operator_code     VARCHAR(10)  NOT NULL,
    operator_name     VARCHAR(50)  NOT NULL,
    email             VARCHAR(100) NOT NULL,
    password_hash     VARCHAR(255) NOT NULL,
    can_approve_order TINYINT(1)   NOT NULL DEFAULT 0,
    is_deleted        TINYINT(1)   NOT NULL DEFAULT 0,
    created_at        DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    created_by        VARCHAR(10)  NOT NULL,
    updated_at        DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    updated_by        VARCHAR(10)  NOT NULL,
    PRIMARY KEY (operator_code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- 在庫（商品ごとに1行：質問No.2）。更新は common/slip.php だけ
CREATE TABLE t_stock (
    product_code VARCHAR(13) NOT NULL,
    stock_qty    INT         NOT NULL DEFAULT 0,
    created_at   DATETIME    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    created_by   VARCHAR(10) NOT NULL,
    updated_at   DATETIME    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    updated_by   VARCHAR(10) NOT NULL,
    PRIMARY KEY (product_code),
    FOREIGN KEY (product_code) REFERENCES m_product (product_code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- 発注伝票（卸業者ごとに1枚）
CREATE TABLE t_order (
    order_no      INT         NOT NULL AUTO_INCREMENT,
    order_date    DATE        NOT NULL,
    supplier_code VARCHAR(10) NOT NULL,
    is_confirmed  TINYINT(1)  NOT NULL DEFAULT 0,
    confirmed_at  DATETIME    NULL DEFAULT NULL,
    confirmed_by  VARCHAR(10) NULL DEFAULT NULL,
    mailed_at     DATETIME    NULL DEFAULT NULL,        -- 発注書メール送信日時（質問No.6）
    created_at    DATETIME    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    created_by    VARCHAR(10) NOT NULL,
    updated_at    DATETIME    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    updated_by    VARCHAR(10) NOT NULL,
    PRIMARY KEY (order_no),
    FOREIGN KEY (supplier_code) REFERENCES m_supplier (supplier_code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- 発注伝票明細
CREATE TABLE t_order_detail (
    order_no       INT          NOT NULL,
    line_no        INT          NOT NULL,
    product_code   VARCHAR(13)  NOT NULL,
    order_qty      INT          NOT NULL,               -- マイナス可、0不可（画面で弾く）
    contract_price INT          NOT NULL,               -- 発注時点の契約単価をコピー
    amount         INT          NOT NULL,               -- contract_price × order_qty
    memo           VARCHAR(200) NULL,
    ref_order_no   INT          NULL DEFAULT NULL,      -- 取消元（マイナス行のみ：質問No.3）
    ref_line_no    INT          NULL DEFAULT NULL,
    created_at     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    created_by     VARCHAR(10)  NOT NULL,
    updated_at     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    updated_by     VARCHAR(10)  NOT NULL,
    PRIMARY KEY (order_no, line_no),
    FOREIGN KEY (order_no) REFERENCES t_order (order_no),
    FOREIGN KEY (product_code) REFERENCES m_product (product_code),
    FOREIGN KEY (ref_order_no, ref_line_no) REFERENCES t_order_detail (order_no, line_no)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- 納品伝票（返品も is_return=1 のマイナス伝票としてここに入る：質問No.4）
CREATE TABLE t_delivery (
    delivery_no   INT         NOT NULL AUTO_INCREMENT,
    delivery_date DATE        NOT NULL,
    supplier_code VARCHAR(10) NOT NULL,
    is_return     TINYINT(1)  NOT NULL DEFAULT 0,       -- 1=返品
    is_confirmed  TINYINT(1)  NOT NULL DEFAULT 0,
    confirmed_at  DATETIME    NULL DEFAULT NULL,
    confirmed_by  VARCHAR(10) NULL DEFAULT NULL,
    created_at    DATETIME    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    created_by    VARCHAR(10) NOT NULL,
    updated_at    DATETIME    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    updated_by    VARCHAR(10) NOT NULL,
    PRIMARY KEY (delivery_no),
    FOREIGN KEY (supplier_code) REFERENCES m_supplier (supplier_code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- 納品伝票明細（どの発注明細に対する納品かを order_no / order_line_no で持つ）
CREATE TABLE t_delivery_detail (
    delivery_no    INT          NOT NULL,
    line_no        INT          NOT NULL,
    order_no       INT          NOT NULL,
    order_line_no  INT          NOT NULL,
    product_code   VARCHAR(13)  NOT NULL,
    delivery_qty   INT          NOT NULL,
    contract_price INT          NOT NULL,
    amount         INT          NOT NULL,
    memo           VARCHAR(200) NULL,                   -- 返品伝票では返品理由
    created_at     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    created_by     VARCHAR(10)  NOT NULL,
    updated_at     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    updated_by     VARCHAR(10)  NOT NULL,
    PRIMARY KEY (delivery_no, line_no),
    FOREIGN KEY (delivery_no) REFERENCES t_delivery (delivery_no),
    FOREIGN KEY (order_no, order_line_no) REFERENCES t_order_detail (order_no, line_no),
    FOREIGN KEY (product_code) REFERENCES m_product (product_code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- 売上（日付×商品）。(sales_date, product_code) に UNIQUE は付けない（質問No.8）
CREATE TABLE t_sales (
    sales_no     INT         NOT NULL AUTO_INCREMENT,
    sales_date   DATE        NOT NULL,
    product_code VARCHAR(13) NOT NULL,
    sales_qty    INT         NOT NULL,
    amount       INT         NOT NULL,                  -- list_price × sales_qty
    is_confirmed TINYINT(1)  NOT NULL DEFAULT 0,
    confirmed_at DATETIME    NULL DEFAULT NULL,
    confirmed_by VARCHAR(10) NULL DEFAULT NULL,
    created_at   DATETIME    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    created_by   VARCHAR(10) NOT NULL,
    updated_at   DATETIME    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    updated_by   VARCHAR(10) NOT NULL,
    PRIMARY KEY (sales_no),
    FOREIGN KEY (product_code) REFERENCES m_product (product_code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- 棚卸（差異がある商品だけ記録）
CREATE TABLE t_stocktaking (
    stocktaking_no   INT          NOT NULL AUTO_INCREMENT,
    stocktaking_date DATE         NOT NULL,
    product_code     VARCHAR(13)  NOT NULL,
    book_qty         INT          NOT NULL,             -- 帳簿数（棚卸前の t_stock.stock_qty）
    actual_qty       INT          NOT NULL,             -- 実数
    diff_qty         INT          NOT NULL,             -- actual_qty − book_qty
    reason           VARCHAR(200) NOT NULL,
    created_at       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    created_by       VARCHAR(10)  NOT NULL,
    updated_at       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    updated_by       VARCHAR(10)  NOT NULL,
    PRIMARY KEY (stocktaking_no),
    FOREIGN KEY (product_code) REFERENCES m_product (product_code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
