#!/bin/bash
# ============================================================
# 1NCO - Fresh winners & transactions (every 2 minutes)
# Inserts a small random batch of wins/losses with recent
# timestamps so the homepage "Latest Winners / Latest
# Transactions" lists keep changing. Runs via cron every
# 2 minutes. Keeps user balances consistent (post_balance).
# ============================================================

# Lock: skip if a previous run is still going (safety on production)
exec 9>/tmp/daily_fresh.lock
flock -n 9 || exit 0

DOCKER=$(command -v docker)
MYSQL="$DOCKER exec -i xaxino-mysql mysql -uxaxino -pc72574e70f9c8a9a8ca5195f34beeca1 xaxino"

SQL_FILE=$(mktemp /tmp/fresh.XXXXXX.sql)
trap 'rm -f "$SQL_FILE"' EXIT

NW=1                     # 1 winner every run
NL=0
[ $((RANDOM % 3)) -eq 0 ] && NL=1   # 1 loss ~33% of runs

echo "SET @now = NOW();" > "$SQL_FILE"

# ---------- Winners ----------
for i in $(seq 1 "$NW"); do
    IFS=$'\t' read -r USERID GID GNAME <<< "$($MYSQL -N -B -e "SELECT u.id, g.id, g.name FROM users u CROSS JOIN games g WHERE u.status=1 AND g.status=1 ORDER BY RAND() LIMIT 1;")"
    [ -z "$USERID" ] && continue

    RAN=$((RANDOM * 100000 / 32767))                  # 0 - 99999
    INVEST=$((50000 + RAN % 950000))                  # 50rb - 1jt
    MULT=$((120 + RANDOM * 680 / 32767))              # 120 - 800 (1.2x - 8.0x)
    WIN=$((INVEST * MULT / 100))
    TRX=$(openssl rand -hex 8 | tr '[:lower:]' '[:upper:]')
    TS="DATE_SUB(@now, INTERVAL $((RANDOM % 51)) MINUTE)"

    cat >> "$SQL_FILE" <<EOF
INSERT INTO game_logs (user_id, game_id, game_name, invest, win_amo, status, win_status, try, mines, mine_available, gold_count, demo_play, result, created_at, updated_at)
VALUES ($USERID, $GID, '$GNAME', $INVEST, $WIN, 1, 1, 1, 0, 0, 0, 0, 'win', $TS, $TS);
UPDATE users SET balance = balance + $WIN WHERE id = $USERID;
INSERT INTO transactions (user_id, amount, charge, post_balance, trx_type, trx, details, remark, created_at, updated_at)
SELECT $USERID, $WIN, 0, balance, '+', '$TRX', 'Win in $GNAME', 'win_bonus', $TS, $TS FROM users WHERE id = $USERID;
EOF
done

# ---------- Losses ----------
for i in $(seq 1 "$NL"); do
    IFS=$'\t' read -r USERID GID GNAME BAL <<< "$($MYSQL -N -B -e "SELECT u.id, g.id, g.name, u.balance FROM users u CROSS JOIN games g WHERE u.status=1 AND g.status=1 AND u.balance >= 200000 ORDER BY RAND() LIMIT 1;")"
    [ -z "$USERID" ] && continue

    BAL_INT=${BAL%%.*}
    MAXL=$((BAL_INT / 2))
    [ "$MAXL" -gt 500000 ] && MAXL=500000
    [ "$MAXL" -lt 50000 ] && MAXL=50000
    RAN=$((RANDOM * 100000 / 32767))
    INVEST=$((50000 + RAN % (MAXL - 49999)))
    TRX=$(openssl rand -hex 8 | tr '[:lower:]' '[:upper:]')
    TS="DATE_SUB(@now, INTERVAL $((RANDOM % 51)) MINUTE)"

    cat >> "$SQL_FILE" <<EOF
INSERT INTO game_logs (user_id, game_id, game_name, invest, win_amo, status, win_status, try, mines, mine_available, gold_count, demo_play, result, created_at, updated_at)
VALUES ($USERID, $GID, '$GNAME', $INVEST, 0, 1, 0, 1, 0, 0, 0, 0, 'loss', $TS, $TS);
UPDATE users SET balance = balance - $INVEST WHERE id = $USERID;
INSERT INTO transactions (user_id, amount, charge, post_balance, trx_type, trx, details, remark, created_at, updated_at)
SELECT $USERID, $INVEST, 0, balance, '-', '$TRX', 'Loss in $GNAME', 'win_bonus', $TS, $TS FROM users WHERE id = $USERID;
EOF
done

$MYSQL < "$SQL_FILE" || exit 1
echo "[$(date '+%Y-%m-%d %H:%M:%S')] inserted $NW winners, $NL losses"
