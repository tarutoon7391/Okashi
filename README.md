# Okashi — お土産屋さん用 発注管理システム

発注 → 発注確定（発注書PDFをメール送信）→ 納品 → 在庫更新 → 売上・棚卸・返品 → 明細表・集計、までを扱う PHP + MySQL の Web システム。
設計書（01 命名規則 / 02 画面一覧 / 04 テーブル定義 / 05 共通部品）どおりの構成で、画面一覧の全画面（SC-00〜SC-75）が入っています。

- 言語：PHP 8.1 以上（素の PHP。フレームワークなし）／ JavaScript（ライブラリなし）
- DB：MySQL 8（ローカルは XAMPP の MariaDB でも動く）
- 本番：Railway（GitHub の `main` に push すると自動デプロイ）
- 外部ライブラリ：TCPDF（PDF）・PHPMailer（メール）… composer で `lib/` に入る（git には入れない）

---

## フォルダ構成（01 命名規則 §3.1）

```
/                         ← プロジェクトルート（= DocumentRoot）
├─ index.php              SC-00  ログイン
├─ otp_input.php          SC-00b ワンタイムコード入力
├─ logout.php                    ログアウト
├─ menu.php               SC-01  メニュー
├─ common/                共通部品（SE・担当Aのみ触る）
│   ├─ config.php         接続情報・定数（環境変数から読む）
│   ├─ db.php             getDb()
│   ├─ auth.php           requireLogin() / requireApprover() / login() / verifyOtp() …
│   ├─ functions.php      h() / redirect() / setFlash() / formatYen() …
│   ├─ slip.php           confirmOrder() / confirmDelivery() / confirmSales() / applyStocktaking()  ← 在庫はここだけ
│   ├─ mail.php           sendMail() / sendOtpMail() / sendOrderMail()
│   ├─ pdf.php            outputPdf()
│   ├─ query.php          【追加】複数画面で使う SELECT（発注伝票・未納品一覧・返品可能数・選択肢）
│   ├─ report.php         【追加】管理業務の絞り込みフォーム
│   ├─ header.php / footer.php
├─ order/                 SC-10〜13  発注（担当B）
├─ delivery/              SC-20〜22  納品（担当C）
├─ return_goods/          SC-50      返品（担当C）
├─ sales/                 SC-30〜31  売上（担当D）
├─ stocktaking/           SC-40      棚卸（担当D）
├─ report/                SC-60〜69  明細表・集計（担当D・E）
├─ master/                SC-70〜75  マスタ（担当A）
├─ css/style.css          共通スタイル（状態色クラス入り）
├─ js/                    common.js と画面固有 JS
├─ img/product/           商品写真（アップロード先）
├─ sql/                   01_create_tables / 02_insert_master / 03_test_data
├─ template/              画面テンプレート（新しい画面はここからコピー）
├─ tools/db_init.php      DB 初期化（コマンドライン専用）
├─ docs/                  画面設計・テスト仕様書
│   └─ 設計書/            要件定義書・01〜06・03作業指示書（元ファイル）
│
├─ Dockerfile / docker/   Railway 用（Apache + PHP 8.2）
├─ railway.json           Railway のビルド・デプロイ設定
├─ composer.json          TCPDF・PHPMailer（lib/ に入る）
├─ start_local.bat        ローカル起動（XAMPP）
└─ .github/               自動チェック（構文・SQL）と PR テンプレート
```

`common/` `sql/` `tools/` `lib/` `docs/` などは Railway 上ではブラウザから開けないようにしてあります（`docker/apache.conf`）。

---

## ローカルで動かす（XAMPP・管理者権限なしで OK）

1. XAMPP コントロールパネルで **MySQL だけ Start**（Apache は使わない）
2. このフォルダの `start_local.bat` をダブルクリック
   - 初回はテーブル作成（sql/01 → 02）まで自動でやって、ブラウザで http://localhost:8000/ が開きます
   - テストデータも入れたいとき：コマンドプロンプトで `start_local.bat reset`（DB を作り直して 01→02→03）
3. ログイン（初期パスワード）

   | 操作者 | パスワード | 権限 |
   |---|---|---|
   | OP001 店長 | pass1234 | 発注承認可 |
   | OP002 販売スタッフA | pass2345 | － |
   | OP003 販売スタッフB | pass3456 | － |

   ローカルは `MAIL_DRIVER=log`（メールを送らないモード）なので、**ワンタイムコードは画面に表示されます**。

### PDF を本物で出したいとき（任意）

`lib/` が無いと、帳票は「印刷用 HTML」で表示されます（PDF にはならないが中身は同じ）。
本物の PDF で確認したいときは composer で入れます（管理者権限不要）：

```
curl -sSL -o composer.phar https://getcomposer.org/download/latest-stable/composer.phar
C:\xampp\php\php.exe composer.phar install
```

---

## Railway へのデプロイ（最初の1回だけ SE がやる）

1. Railway で **New Project → Deploy from GitHub repo → `tarutoon7391/Okashi`**
   - `railway.json` があるので Dockerfile で自動ビルドされる
2. 同じプロジェクトに **+ New → Database → MySQL** を追加
3. アプリ側サービスの **Variables** に以下を入れる（`${{MySQL.…}}` は Railway の参照変数。MySQL サービス名が違うなら合わせる）

   | 変数 | 値 |
   |---|---|
   | `DB_HOST` | `${{MySQL.MYSQLHOST}}` |
   | `DB_PORT` | `${{MySQL.MYSQLPORT}}` |
   | `DB_USER` | `${{MySQL.MYSQLUSER}}` |
   | `DB_PASS` | `${{MySQL.MYSQLPASSWORD}}` |
   | `DB_NAME` | `omiyage_order` |
   | `MAIL_DRIVER` | 最初は `log`（下の「メール」参照） |
   | `SEED_TEST_DATA` | デモ用にテストデータを入れるなら `1`（テーブルを初めて作るときだけ効く） |
   | `SHOP_NAME` | 発注書に出す店名（任意） |

4. **Settings → Networking → Generate Domain** で URL を発行
5. デプロイが終わると、起動時に `tools/db_init.php` が走ってテーブル（と初期データ）が自動で作られる

以後は **`main` に push（= PR をマージ）するだけで自動デプロイ**されます。

### メール（二要素認証・発注書）について

- Railway は **Free / Hobby プランだと SMTP（Gmail など）が使えません**（Pro 以上のみ）。
  - Hobby のままメールを送るなら **Resend**（HTTP でメールを送るサービス。無料枠あり）を使う：
    `MAIL_DRIVER=resend` / `RESEND_API_KEY=…` / `MAIL_FROM=（Resend で認証したドメインのアドレス）`
  - Pro プランなら Gmail：`MAIL_DRIVER=smtp` / `SMTP_USER=…@gmail.com` / `SMTP_PASS=（アプリパスワード）` / `MAIL_FROM=…@gmail.com`
- 発表デモなどでメールを使わないなら `MAIL_DRIVER=log` のままで OK（コードが画面に出る）。
- `sql/02_insert_master.sql` のメールアドレスは `xxxx@gmail.com` のダミー。実際に受け取るなら書き換えるか、操作者マスタ画面で変更する。
- **パスワード・API キーはファイルに書かず、必ず Railway の Variables に入れる**（このリポジトリは公開されています）。

### 商品写真

Railway のコンテナは再デプロイで中身が消えるので、写真を残すならアプリ側サービスに **Volume** を追加して
マウント先を `/var/www/html/img/product` にする。

### DB を作り直したいとき

Railway の MySQL サービス → Data タブでテーブルを消してから再デプロイ（起動時に作り直される）。
ローカルは `start_local.bat reset`。

---

## チーム開発の流れ（GitHub）

`main` = 本番（Railway に自動デプロイ）。**直接 push しない**で、ブランチ → プルリクエスト → SE がマージ。

```
git switch main
git pull
git switch -c b/order-input          # ブランチ名は「担当/機能」
（作業 → start_local.bat で確認）
git add order/order_input.php js/order_input.js
git commit -m "SC-10 発注入力の登録処理"
git push -u origin b/order-input     # → GitHub でプルリクエストを作る
```

- PR を出すと GitHub Actions が **全 PHP の構文チェック** と **SQL（01→02→03）が MySQL 8 で流れるか** を自動確認します
- 自分の担当以外のファイル（特に `common/`）は触らない。直してほしいときは SE か担当に言う
- 画面を新しく作るときは `template/screen_template.php` をコピー

### 担当とファイル

| 担当 | ファイル |
|---|---|
| SE（siba） | `common/auth.php` `slip.php` `mail.php`、`index.php` `otp_input.php` `logout.php` `menu.php`、Railway 設定 |
| A（kane） | `common/config.php` `db.php` `functions.php` `header.php` `footer.php` `pdf.php` `query.php` `report.php`、`master/*`、`sql/01` `02`、`css/style.css` `js/common.js` |
| B（yama） | `order/*`、`js/order_input.js` |
| C（sasa） | `delivery/*` `return_goods/*`、`js/delivery_input.js` |
| D（yasu） | `sales/*` `stocktaking/*`、`report/sales_*` `report/stocktaking_list.php`、`js/stocktaking_input.js` |
| E（tamu） | `report/order_*` `delivery_*` `return_goods_*` `supplier_summary.php`、`js/supplier_summary.js`、`sql/03`、`docs/テスト仕様書.xlsx` |

---

## 設計書からの追加・変更点（SE が 05 共通部品設計書に反映する）

| 箇所 | 内容 | 理由 |
|---|---|---|
| `common/config.php` | 値を環境変数から読む（無ければ XAMPP の初期値）。`DB_PORT` `MAIL_DRIVER` `RESEND_API_KEY` `SHOP_NAME` `OTP_MAX_TRIES` `PRODUCT_IMG_DIR` を追加 | Railway とローカルで同じファイルを使うため。パスワードを git に入れないため |
| `common/db.php` | 接続時に `SET time_zone = '+09:00'` | Railway の MySQL は UTC のため |
| `common/mail.php` | `MAIL_DRIVER` で log / smtp / resend を切替 | Railway Hobby は SMTP 不可のため |
| `common/pdf.php` | `lib/` が無いときは印刷用 HTML を表示 | composer 無しでもローカル開発できるように |
| `common/query.php` | 新規。`getOrderSlip()` `buildOrderSheetHtml()` `getUndeliveredList()` `getReturnableList()` など | 作業指示書 B・C の「共通化する取得関数」の置き場所 |
| `common/report.php` | 新規。`getReportFilter()` `buildReportWhere()` `renderReportFilter()` | 明細表・集計 9 画面の絞り込みを共通化 |
| `common/slip.php` | `applyStocktaking()` に棚卸日（省略可）を追加、`getMinusStockProducts()` を追加 | 棚卸日を画面で入力するため／在庫マイナスの警告表示 |
| `common/functions.php` | `postArray()` `isValidDate()` `toIntOrNull()` `storageBadge()` `statusCell()` などを追加 | 明細行の配列入力・状態色の共通化 |
| `lib/` | composer（`vendor-dir: lib`）で入れる。git には入れない | TCPDF が大きいため |
| 未納品数 | 取消は**確定済み**のマイナス発注だけ差し引く。納品は**未確定も含めて**差し引く | 二重に納品入力しないため |
| 操作者マスタ | 新規登録・パスワード・承認権限の変更は承認可の人だけ。最後の承認可は外せない／削除できない | 質問No.5。承認者が0人になると発注確定できなくなるため |
