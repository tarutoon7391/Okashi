# 画面設計（担当C：納品）お手本　コマ3

---

## SC-20 納品入力　delivery/delivery_input.php　（権限：ログイン済）

3段構成：①卸業者選択（GET） → ②未納品一覧でチェック＋数量 → ③登録（POST）

### 入力項目
| 段 | 項目 | name | 型 | 必須 | 初期値 | チェック |
|---|---|---|---|---|---|---|
| ① | 卸業者 | supplier_code | select | ○ | 未選択 | is_deleted=0 の卸業者 |
| ② | 納品日 | delivery_date | date | ○ | 今日 | |
| ② | 納品対象 | （チェックボックス。name なし。外した行は入力欄を disabled にして送らない） | checkbox | 1件以上 | OFF | |
| ② | 発注No・行No | order_no[] / order_line_no[] | hidden | | | 選んだ卸業者の確定済み明細であること |
| ② | 納品数 | delivery_qty[] | number | チェック行は○ | 残数 | 整数・0不可・マイナス可（訂正用） |

### 表示項目（未納品一覧）
発注日・発注No-行・商品コード・商品名・規格（保存区分バッジ）・発注数・取消・納品済・**残数**・契約単価・納品金額（自動計算）・合計

**残数 ＝ 発注数 ＋ 取消数（ref で紐付くマイナス発注の合計）− 納品数合計（is_return=0 の納品伝票のみ）**
- 確定済み（t_order.is_confirmed=1）のプラスの発注明細だけを対象にする
- 残数 > 0 の行だけ出す。マイナス発注（取消）の行そのものは出さない（質問No.3, 4）
- このSQLは SC-22 未納品一覧表と共通 → 関数 getUndelivered($supplierCode, $from, $to) にする

### ボタンと遷移
| ボタン | 動作 | 遷移先 |
|---|---|---|
| 未納品を表示（①） | GET で再表示 | SC-20 |
| 納品伝票を登録（③） | t_delivery（is_return=0）を1行、明細を t_delivery_detail に INSERT。product_code・contract_price は発注明細からコピー | SC-20（完了メッセージ） |
| メニューへ戻る | | SC-01 |

---

## SC-21 納品確定　delivery/delivery_confirm.php　（権限：ログイン済）

### 入力項目
| 項目 | name | 型 | 必須 |
|---|---|---|---|
| 確定する伝票 | delivery_no[] | checkbox | 1件以上 |

### 表示項目
未確定の納品伝票一覧（**返品伝票も含む**）：伝票No・納品日・卸業者・区分（納品／返品）・明細（商品・数量）・合計金額・状態
- 返品伝票（is_return=1）の行は **.row-return**（薄紫）、状態は .status-unconfirmed

### ボタンと遷移
| ボタン | 動作 | 遷移先 |
|---|---|---|
| 確定（.btn-danger・confirmSubmit） | 伝票ごとに confirmDelivery()（在庫が増える／返品なら減る） | SC-22 未納品一覧表 |
| メニューへ戻る | | SC-01 |

---

## SC-22 未納品一覧表　delivery/undelivered_print.php　（PDF帳票）

| 区分 | 内容 |
|---|---|
| 絞り込み | 卸業者（すべて可）・期間（発注日 from〜to） |
| 列 | 発注日・卸業者・商品コード・商品名・規格・発注数・納品済・残数量 |
| 並び | 卸業者 → 発注日 → 商品 |
| 遷移 | 「納品入力へ」→ SC-20、「戻る」→ SC-21 |
