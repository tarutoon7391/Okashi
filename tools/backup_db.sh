#!/bin/bash
# データベースのバックアップ（要件定義書 §4：1週間でフルバックアップ、1日単位で差分バックアップ）
# 手順・スケジュールの決め方は docs/運用手順書.md を参照
#
#   bash tools/backup_db.sh           … フルバックアップ（全テーブルの構造＋データ）。週1回（例：日曜夜）
#   bash tools/backup_db.sh diff      … 差分バックアップ。直近のフルバックアップ以降に追加・更新された行だけ。毎日（業務終了後）
#
# 出力：$BACKUP_DIR（既定 ./backup）に
#   <DB名>_full_YYYYMMDD_HHMMSS.sql.gz ／ <DB名>_diff_YYYYMMDD_HHMMSS.sql.gz
#   フルのときは last_full.txt に開始日時を書く（差分はこの日時以降の行を取る）
#
# 接続先（環境変数。Railway の MySQL サービスの Variables と同じ名前）
#   MYSQLHOST / MYSQLPORT / MYSQLUSER / MYSQLPASSWORD
#   DB_NAME（既定 omiyage_order。アプリと同じ）
#   ローカルPCから Railway に繋ぐときは、MySQL サービスの「Public Networking」の
#   ホスト名（xxx.proxy.rlwy.net）とポートを MYSQLHOST / MYSQLPORT に入れる
#   BACKUP_KEEP_DAYS（既定 42）：これより古いバックアップファイルを消す（フル6週分）
#
# 注意
#   ・差分は updated_at を見て取るため、「行の削除」は差分に入らない（次のフルバックアップで反映される）
#   ・パスワードはコマンドラインに出さないよう MYSQL_PWD で mysqldump に渡す
#   ・バックアップファイルには操作者のメールアドレス等が入る。GitHub 等の公開場所に置かないこと
set -euo pipefail

MODE="${1:-full}"
HOST="${MYSQLHOST:-${DB_HOST:-localhost}}"
PORT="${MYSQLPORT:-${DB_PORT:-3306}}"
USER="${MYSQLUSER:-${DB_USER:-root}}"
export MYSQL_PWD="${MYSQLPASSWORD:-${DB_PASS:-}}"
DB="${DB_NAME:-omiyage_order}"
BACKUP_DIR="${BACKUP_DIR:-./backup}"
KEEP_DAYS="${BACKUP_KEEP_DAYS:-42}"
MYSQLDUMP="${MYSQLDUMP:-mysqldump}"

# 差分の対象（全テーブルに updated_at がある）
TABLES="m_supplier m_product m_operator t_stock t_order t_order_detail t_delivery t_delivery_detail t_sales t_stocktaking"

mkdir -p "$BACKUP_DIR"
NOW="$(date '+%Y%m%d_%H%M%S')"
# DB 側の時刻（アプリは +09:00 で書き込む）に合わせるため日本時間で記録する
# （Asia/Tokyo はタイムゾーンデータの無い環境（Git Bash 等）で効かないので POSIX 形式の JST-9 を使う）
STARTED="$(TZ=JST-9 date '+%Y-%m-%d %H:%M:%S')"
COMMON_OPTS=(--host="$HOST" --port="$PORT" --user="$USER" --single-transaction --no-tablespaces --default-character-set=utf8mb4)

case "$MODE" in
  full)
    OUT="$BACKUP_DIR/${DB}_full_${NOW}.sql.gz"
    # --databases を付けると CREATE DATABASE / USE も入るので、そのまま流せば復元できる
    "$MYSQLDUMP" "${COMMON_OPTS[@]}" --routines --triggers --databases "$DB" | gzip > "$OUT"
    echo "$STARTED" > "$BACKUP_DIR/last_full.txt"
    ;;
  diff)
    if [ ! -f "$BACKUP_DIR/last_full.txt" ]; then
      echo "[backup] フルバックアップがまだありません。先に full を実行してください" >&2
      exit 1
    fi
    SINCE="$(cat "$BACKUP_DIR/last_full.txt")"
    OUT="$BACKUP_DIR/${DB}_diff_${NOW}.sql.gz"
    # --replace：復元時に同じ主キーの行を上書き（フル → 最新の差分1本 の順に流す）
    # --no-create-info：テーブルは作り直さない（フルで作る）
    {
      echo "-- 差分バックアップ：${SINCE} 以降に追加・更新された行（削除は含まない）"
      echo "USE \`${DB}\`;"
      echo "SET FOREIGN_KEY_CHECKS = 0;"
      "$MYSQLDUMP" "${COMMON_OPTS[@]}" --no-create-info --replace --skip-triggers \
        --where="updated_at >= '${SINCE}'" "$DB" $TABLES
      echo "SET FOREIGN_KEY_CHECKS = 1;"
    } | gzip > "$OUT"
    ;;
  *)
    echo "使い方：bash tools/backup_db.sh [full|diff]" >&2
    exit 1
    ;;
esac

# 中身が空（接続失敗など）なら失敗扱い
if [ "$(gzip -dc "$OUT" | head -c 100 | wc -c)" -eq 0 ]; then
  echo "[backup] バックアップが空です：$OUT" >&2
  exit 1
fi
echo "[backup] 作成しました：$OUT（$(du -h "$OUT" | cut -f1)）"

# 古いバックアップを削除
find "$BACKUP_DIR" -name "${DB}_*.sql.gz" -type f -mtime +"$KEEP_DAYS" -print -delete || true
