# 画面設計（担当E：管理業務）お手本　コマ3

---

## 明細表・集計の共通レイアウト（SC-60〜SC-68 で使い回す）

### 構成
1. 絞り込みフォーム（GET）
2. 結果テーブル（.data-table）
3. 合計行（tfoot）

### 絞り込み項目
| 項目 | name | 型 | 初期値 | 備考 |
|---|---|---|---|---|
| 期間 from | date_from | date | 当月1日 | 未指定なら当月 |
| 期間 to | date_to | date | 当月末日 | |
| 卸業者 | supplier_code | select | すべて | **売上系（SC-66〜68）には出さない** |
| 商品 | product_code | select | すべて | カナ昇順 |

※「集計単位」の選択は置かない

### 結果テーブル
- 明細表（*_list）：卸業者別に並べ、卸業者ごとに小計、最後に合計
- 集計（*_summary）：GROUP BY product_code で 商品コード・商品名・規格・SUM(数量)・SUM(金額)、最後に合計
- マイナス行は .qty-minus、確定状態は .status-unconfirmed / .status-confirmed

### 画面ごとの差し替え表
| 画面ID | ファイル | 担当 | 対象 | 絞り込み | 返品・マイナスの扱い |
|---|---|---|---|---|---|
| SC-60 | report/order_list.php | E | t_order + t_order_detail | 期間・卸業者・商品 | 取消（マイナス）も含めて表示 |
| SC-61 | report/order_summary.php | E | 同上 | 期間・卸業者・商品 | マイナス伝票も含めて合算 |
| SC-62 | report/delivery_list.php | E | t_delivery + t_delivery_detail | 期間・卸業者・商品 | is_return=0 のみ |
| SC-63 | report/delivery_summary.php | E | 同上 | 期間・卸業者・商品 | is_return=0 のみ |
| SC-64 | report/return_goods_list.php | E | 同上 | 期間・卸業者・商品 | is_return=1 のみ。**符号を反転して表示** |
| SC-65 | report/return_goods_summary.php | E | 同上 | 期間・卸業者・商品 | is_return=1 のみ。卸業者別に集計・符号反転 |
| SC-66 | report/sales_list.php | D | t_sales | 期間・商品 | マイナス（訂正行）も含める |
| SC-67 | report/sales_summary.php | D | t_sales | 期間・商品 | 金額＝定価×数量 |
| SC-68 | report/stocktaking_list.php | D | t_stocktaking | 期間・商品 | 帳簿数・実数・差異・理由 |

### ボタンと遷移
| ボタン | 動作 | 遷移先 |
|---|---|---|
| 表示 | GET で再表示 | 同画面 |
| 条件クリア | パラメータなしで再表示（当月） | 同画面 |
| メニューへ戻る | | SC-01 |

---

## SC-69 卸業者別納品金額　report/supplier_summary.php

### 入力項目
| 項目 | name | 型 | 初期値 |
|---|---|---|---|
| 期間 from / to | date_from / date_to | date | 当月 |

### 表示項目
- 表：卸業者コード・卸業者名・納品金額・返品金額・**純額（納品＋返品）**・構成比（%）・合計
- 円グラフ：純額の割合。canvas の arc() で描く（js/supplier_summary.js・ライブラリなし）。凡例はHTMLの表と同じ色
- データは PHP が JSON で埋め込む（例：`<script id="chartData" type="application/json">…</script>`）
- 純額が0以下の卸業者はグラフに出さない（表には出す）

### ボタンと遷移
| ボタン | 動作 | 遷移先 |
|---|---|---|
| 表示 | GET で再表示 | SC-69 |
| メニューへ戻る | | SC-01 |

### 注意
- 表の数字とグラフの数字が一致すること（完了条件）
- 明細表・集計は SELECT のみ。伝票を更新する処理は書かない
