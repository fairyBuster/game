# HOW TO INSTALL — 1necoworld (Xaxino) di Server Baru

Panduan migrasi + instalasi lengkap. Semua perintah dijalankan sebagai user `ubuntu` (boleh user lain, sesuaikan path).

---

## 0. Arsitektur Singkat

Aplikasi berjalan sebagai stack **Docker Compose** dari folder `Files/core`:

| Container | Image | Port (host) | Fungsi |
|---|---|---|---|
| `xaxino-caddy` | build custom (`xaxino-caddy-rl`) | **80, 443 (publik)** | Reverse proxy + TLS (Caddy), rate limit |
| `xaxino-app` | build dari `core/Dockerfile` | `127.0.0.1:5555` | PHP-FPM 8.3 + Nginx + Supervisor (queue worker & scheduler) |
| `xaxino-mysql` | `mysql:8.4` | `127.0.0.1:3306` | Database `xaxino` |
| `xaxino-redis` | `redis:7-alpine` | `127.0.0.1:6379` | Cache / session / queue |

- **Web root**: folder `Files/` di-mount ke `/var/www/html`; Nginx serve dari `/var/www/html/core/public` (Laravel di `core/`).
- **Domain**: `https://1necoworld.com` (Cloudflare → server, TLS otomatis via Caddy).
- Volume persisten: `mysql_data`, `redis_data`, `caddy_data`, `caddy_config`.

Struktur folder yang harus ada di server baru:

```
/home/ubuntu/game/
├── Files/                      # aplikasi (web root) — WAJIB
│   ├── install/database.sql    # skema awal (hanya dipakai saat mysql boot pertama)
│   └── core/
│       ├── .env                # konfigurasi — WAJIB disesuaikan
│       ├── docker-compose.yml  # stack utama
│       ├── Dockerfile
│       └── docker/             # nginx, caddy, supervisor, entrypoint
├── daily_fresh.sh              # script cron "Latest Winners" (tiap 2 menit)
└── xaxino_db_20260922.sql.gz   # dump database (sudah dibuat, 1.6 MB)
```

---

## 1. Requirement Server

- Ubuntu 22.04+ (64-bit), RAM minimal 2 GB (4 GB disarankan), disk ≥ 20 GB.
- Port **80, 443, 22** terbuka. Port 3306/6379/5555 hanya bind ke 127.0.0.1 (tidak perlu dibuka).
- Docker Engine + Docker Compose v2 (plugin).
- Domain diarahkan ke IP server baru (A record Cloudflare).

### Install Docker (Ubuntu)

```bash
sudo apt update && sudo apt install -y ca-certificates curl
sudo install -m 0755 -d /etc/apt/keyrings
sudo curl -fsSL https://download.docker.com/linux/ubuntu/gpg -o /etc/apt/keyrings/docker.asc
sudo chmod a+r /etc/apt/keyrings/docker.asc
echo "deb [arch=$(dpkg --print-architecture) signed-by=/etc/apt/keyrings/docker.asc] https://download.docker.com/linux/ubuntu $(. /etc/os-release && echo $VERSION_CODENAME) stable" | sudo tee /etc/apt/sources.list.d/docker.list > /dev/null
sudo apt update
sudo apt install -y docker-ce docker-ce-cli containerd.io docker-buildx-plugin docker-compose-plugin
sudo usermod -aG docker $USER   # logout/login agar aktif
```

Firewall (jika pakai ufw):

```bash
sudo ufw allow 22 && sudo ufw allow 80 && sudo ufw allow 443 && sudo ufw enable
```

---

## 2. Pindahkan File dari Server Lama

Dari **server baru**, tarik semua file dari server lama:

```bash
rsync -avz --progress ubuntu@IP-SERVER-LAMA:/home/ubuntu/game/ /home/ubuntu/game/
```

Dari **server lama** (alternatif, push):

```bash
rsync -avz --progress /home/ubuntu/game/ ubuntu@IP-SERVER-BARU:/home/ubuntu/game/
```

Catatan:
- Server lama = sumber, biarkan tetap jalan sampai server baru terverifikasi.
- File `xaxino_db_20260922.sql.gz` harus ikut terbawa (dibuat 22 Sep).
- Jika folder `.git` tidak diperlukan, boleh di-exclude (`--exclude '.git'`).
- `daily_fresh.log` boleh di-exclude (file log, besar).

---

## 3. Konfigurasi `.env`

Edit `Files/core/.env` di server baru:

```ini
APP_NAME=1NCO
APP_ENV=production
APP_KEY=base64:...            # dibiarkan — akan di-generate ulang otomatis saat boot (lihat catatan)
APP_DEBUG=false               # SANGAT disarankan false di production (sekarang masih true)
APP_URL=https://1necoworld.com
APP_TIMEZONE=UTC

DB_CONNECTION=mysql
DB_HOST=mysql                 # nama service compose, JANGAN diganti 127.0.0.1
DB_PORT=3306
DB_DATABASE=xaxino
DB_USERNAME=xaxino
DB_PASSWORD=<password-db>     # lihat .env server lama; boleh diganti (ubah juga di daily_fresh.sh!)

REDIS_CLIENT=phpredis
REDIS_HOST=redis              # nama service compose
REDIS_PASSWORD=<password-redis>
REDIS_PORT=6379

APP_PORT=5555
PURCHASECODE=viserlab
```

Penting:
- `DB_PASSWORD` = password root MySQL **sekaligus** user `xaxino` (dipakai `MYSQL_ROOT_PASSWORD` di compose).
- `REDIS_PASSWORD` dipakai oleh container redis & aplikasi (dibaca dari `.env` yang sama).
- **APP_KEY di-generate ulang setiap container start** oleh `entrypoint.sh` (`artisan key:generate --force`). Ini perilaku server lama juga, jadi tidak masalah — semua user akan diminta login ulang saja. Kalau mau APP_KEY permanen, hapus baris `php artisan key:generate` di `docker/entrypoint.sh`.
- Kalau `DB_PASSWORD` diganti, WAJIB update juga `daily_fresh.sh` (password tertulis hardcoded di dalam script).

---

## 4. Build & Jalankan Stack

```bash
cd /home/ubuntu/game/Files/core

# Build image app (composer install ikut di dalam) + caddy (build plugin rate_limit)
docker compose build

# Jalankan semua container
docker compose up -d

# Pastikan semua UP (mysql menunggu status healthy)
docker compose ps
```

Yang otomatis terjadi saat pertama kali (via `entrypoint.sh`):

1. Menunggu MySQL siap.
2. Membuat tabel framework Laravel: `cache`, `cache_locks`, `sessions`, `jobs`, `job_batches`, `failed_jobs` (kalau belum ada).
3. `artisan key:generate`, `storage:link`, `config:cache`, `view:cache`.
4. Normalisasi permission storage (`chgrp -R 33` + `chmod -R g+w`).
5. Copy `Files/assets/*` → `core/public/assets/`.
6. Menjalankan Supervisor: **php-fpm, nginx, queue-worker, scheduler** (`schedule:work` — jadi tidak butuh cron Laravel di host).

Catatan: saat MySQL pertama kali boot dengan volume kosong, `Files/install/database.sql` dimuat sebagai skema awal. Skema itu akan **ditimpa** oleh dump asli di langkah berikutnya (dump berisi 43 `DROP TABLE IF EXISTS` + `CREATE TABLE`).

---

## 5. Import Database

Tunggu sampai `xaxino-mysql` status **healthy**, lalu:

```bash
gunzip < /home/ubuntu/game/xaxino_db_20260922.sql.gz \
  | docker exec -i xaxino-mysql mysql -uxaxino -p'<password-db>' xaxino
```

Atau dari file `.sql` langsung:

```bash
docker exec -i xaxino-mysql mysql -uxaxino -p'<password-db>' xaxino \
  < /home/ubuntu/game/xaxino_db_20260922.sql
```

Verifikasi:

```bash
docker exec xaxino-mysql mysql -uxaxino -p'<password-db>' xaxino -e "SELECT COUNT(*) AS tables_cnt FROM information_schema.tables WHERE table_schema='xaxino'; SELECT COUNT(*) AS users FROM users;"
# Harapan: 43 tabel, jumlah users sesuai server lama
```

Setelah import selesai, hapus dump dari server (berisi data sensitif):

```bash
rm /home/ubuntu/game/xaxino_db_20260922.sql /home/ubuntu/game/xaxino_db_20260922.sql.gz
```

---

## 6. Caddyfile, DNS & TLS

File: `Files/core/docker/caddy/Caddyfile`

Caddyfile di server lama memuat domain **project lain** (`roguecdn.online`, `api.roguecdn.online`, `globalcash.roguecdn.online`, `merchant./admin./status.maycacloud.com` — milik payment platform yang jalan di server yang sama).

**Kalau hanya game yang dipindah**, sisakan blok berikut saja:

```caddyfile
1necoworld.com {
	reverse_proxy app:80

	@static path /assets/*
	header @static Cache-Control "public, max-age=604800"
}
```

Lalu reload Caddy:

```bash
cd /home/ubuntu/game/Files/core
docker compose restart caddy
# atau tanpa downtime:
docker exec xaxino-caddy caddy reload --config /etc/caddy/Caddyfile
```

DNS & Cloudflare:
- A record `1necoworld.com` → IP server baru.
- Cloudflare SSL/TLS mode: **Full (strict)**.
- Caddy menerbitkan sertifikat otomatis via HTTP-01 (butuh port 80 reachable). Kalau gagal, matikan sementara proxy Cloudflare (grey cloud) sampai cert terbit.
- **Update IP server lama** setelah migrasi selesai (hapus/ubah A record lama) supaya tidak ada dua origin.

---

## 7. Cron Host (`daily_fresh.sh`)

Laravel scheduler sudah jalan otomatis di dalam container — **tidak perlu** cron `schedule:run` di host.

Satu-satunya cron host adalah pengisi daftar "Latest Winners / Transactions" di homepage:

```bash
crontab -e
```

Tambahkan:

```
*/2 * * * * /home/ubuntu/game/daily_fresh.sh >> /home/ubuntu/game/daily_fresh.log 2>&1
```

Pastikan script executable:

```bash
chmod +x /home/ubuntu/game/daily_fresh.sh
```

Catatan: script ini memakai `docker exec -i xaxino-mysql mysql -uxaxino -p...` dengan **nama container & password hardcoded** — kalau nama container/password berbeda, edit script-nya.

---

## 8. Verifikasi Akhir (Checklist)

```bash
# 1. App lokal
curl -I -H "Host: 1necoworld.com" http://127.0.0.1:5555

# 2. Domain + TLS
curl -I https://1necoworld.com

# 3. Callback payment (harus "OK" / HTTP 200)
curl https://1necoworld.com/ipn/roguepay

# 4. Log container (supervisor: fpm / nginx / queue / scheduler)
docker logs xaxino-app --tail 50

# 5. Cek gateway deposit masih lengkap
docker exec xaxino-mysql mysql -uxaxino -p'<password-db>' xaxino -e \
  "SELECT gc.name, gc.method_code, g.status FROM gateway_currencies gc JOIN gateways g ON g.code=gc.method_code;"
# Harapan: OST - IDR (511), MGM - QRIS (512), MGM - E-Wallet (514) aktif; MGM VA (513) status 0
```

Checklist manual:
- [ ] Homepage terbuka tanpa error.
- [ ] Login user + login admin berhasil.
- [ ] Halaman deposit menampilkan 3 metode (OST - IDR, MGM - QRIS, MGM - E-Wallet).
- [ ] Test deposit kecil via MGM - QRIS → redirect ke cashier MGM, saldo masuk setelah bayar (webhook).
- [ ] `GET /ipn/roguepay` → `OK`.
- [ ] Homepage "Latest Winners" bertambah setelah ±2 menit (cron jalan).
- [ ] Cloudflare cache di-purge.

Kalau domain tetap `1necoworld.com` (hanya pindah server), **callback URL di dashboard OST tidak perlu diubah** — sudah otomatis menunjuk domain yang sama.

---

## 9. Backup & Maintenance Rutin

Backup database (aman saat live, tanpa lock):

```bash
docker exec xaxino-mysql sh -c 'mysqldump -uxaxino -p"$MYSQL_PASSWORD" \
  --single-transaction --quick --routines --triggers --events --no-tablespaces \
  --set-gtid-purged=OFF --default-character-set=utf8mb4 --hex-blob xaxino' \
  | gzip -9 > /home/ubuntu/game/backup_$(date +%Y%m%d_%H%M).sql.gz
```

Restore:

```bash
gunzip < backup_YYYYMMDD_HHMM.sql.gz | docker exec -i xaxino-mysql mysql -uxaxino -p'<password-db>' xaxino
```

Update kode: karena source di-mount sebagai volume, perubahan PHP langsung terbaca (opcache revalidate 2 detik). Rebuild hanya perlu kalau `Dockerfile`/dependency berubah:

```bash
cd /home/ubuntu/game/Files/core
docker compose build app && docker compose up -d app
```

Backup folder upload user (bukti transfer, file) — di luar database:

```bash
tar czf storage_app_$(date +%Y%m%d).tar.gz -C /home/ubuntu/game/Files/core/storage app
```

---

## 10. Troubleshooting

| Gejala | Penyebab & Solusi |
|---|---|
| Halaman 500 setelah edit template | Compiled view milik root, php-fpm (www-data) tidak bisa menimpa saat recompile. Fix: `docker exec xaxino-app sh -c 'chgrp -R 33 /var/www/html/core/storage && chmod -R g+w /var/www/html/core/storage'`, lalu `docker exec -u www-data xaxino-app php /var/www/html/core/artisan view:clear` |
| `docker compose up` gagal, `.env not found` | Pastikan `Files/core/.env` ada (dipakai `env_file` + interpolasi `${...}`) |
| MySQL unhealthy / app restart terus | Cek `docker logs xaxino-mysql`; pastikan `DB_PASSWORD` tidak kosong & volume lama tidak bentrok |
| Import SQL: `Access denied` | Pastikan container healthy & password sesuai `.env`; user `xaxino` hanya punya akses ke DB `xaxino` |
| Caddy tidak dapat sertifikat | Port 80 harus reachable dari internet; coba matikan proxy Cloudflare sementara; cek `docker logs xaxino-caddy` |
| Build caddy gagal | Image caddy di-build via `xcaddy` (butuh internet ke proxy.golang.org) — cek koneksi server |
| Homepage statis / "Latest Winners" tidak berubah | Cron `daily_fresh.sh` tidak jalan atau password DB di script tidak cocok |
| Deposit MGM error "MGM not configured" / 403 | Sisi platform OST (bukan server game). FF Pay butuh whitelist `DIRECT_PG_API_KEYS` |
| `daily_fresh.log` membengkak | Tambahkan logrotate atau truncate: `: > /home/ubuntu/game/daily_fresh.log` |

---

## TL;DR Urutan Perintah

```bash
# === DI SERVER BARU ===
# 1. Install Docker (§1), lalu:
rsync -avz ubuntu@IP-LAMA:/home/ubuntu/game/ /home/ubuntu/game/

# 2. Edit konfigurasi
nano /home/ubuntu/game/Files/core/.env

# 3. Build & jalankan
cd /home/ubuntu/game/Files/core
docker compose build && docker compose up -d && docker compose ps

# 4. Import database (setelah mysql healthy)
gunzip < /home/ubuntu/game/xaxino_db_20260922.sql.gz \
  | docker exec -i xaxino-mysql mysql -uxaxino -p'<password-db>' xaxino

# 5. Sesuaikan Caddyfile (§6) lalu restart caddy
docker compose restart caddy

# 6. Arahkan DNS domain ke server baru, tunggu TLS terbit
curl -I https://1necoworld.com

# 7. Cron daily_fresh (§7)
chmod +x /home/ubuntu/game/daily_fresh.sh
(crontab -l 2>/dev/null; echo '*/2 * * * * /home/ubuntu/game/daily_fresh.sh >> /home/ubuntu/game/daily_fresh.log 2>&1') | crontab -

# 8. Verifikasi (§8), lalu hapus file dump dari server
```
