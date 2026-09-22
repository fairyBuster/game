<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class IndonesiaUserSeeder extends Seeder
{
    /**
     * Real Indonesian user profiles (name, city, province, zip, mobile)
     */
    protected $profiles = [
        ['firstname' => 'Andi', 'lastname' => 'Setiawan', 'city' => 'Surabaya', 'state' => 'Jawa Timur', 'zip' => '60231', 'mobile' => '081234567890'],
        ['firstname' => 'Dewi', 'lastname' => 'Lestari', 'city' => 'Bandung', 'state' => 'Jawa Barat', 'zip' => '40115', 'mobile' => '082122334455'],
        ['firstname' => 'Budi', 'lastname' => 'Santoso', 'city' => 'Semarang', 'state' => 'Jawa Tengah', 'zip' => '50131', 'mobile' => '085212345678'],
        ['firstname' => 'Siti', 'lastname' => 'Rahayu', 'city' => 'Yogyakarta', 'state' => 'DI Yogyakarta', 'zip' => '55223', 'mobile' => '085723456789'],
        ['firstname' => 'Agus', 'lastname' => 'Prasetyo', 'city' => 'Medan', 'state' => 'Sumatera Utara', 'zip' => '20111', 'mobile' => '081265432109'],
        ['firstname' => 'Rina', 'lastname' => 'Wulandari', 'city' => 'Makassar', 'state' => 'Sulawesi Selatan', 'zip' => '90111', 'mobile' => '082187654321'],
        ['firstname' => 'Eko', 'lastname' => 'Nugroho', 'city' => 'Palembang', 'state' => 'Sumatera Selatan', 'zip' => '30121', 'mobile' => '085298765432'],
        ['firstname' => 'Maya', 'lastname' => 'Sari', 'city' => 'Denpasar', 'state' => 'Bali', 'zip' => '80231', 'mobile' => '089612345678'],
        ['firstname' => 'Joko', 'lastname' => 'Susilo', 'city' => 'Malang', 'state' => 'Jawa Timur', 'zip' => '65111', 'mobile' => '081398765432'],
        ['firstname' => 'Lina', 'lastname' => 'Kurniawati', 'city' => 'Bekasi', 'state' => 'Jawa Barat', 'zip' => '17111', 'mobile' => '089523456789'],
    ];

    /**
     * Deposit amounts pool (small + big, IDR)
     */
    protected $depositSmall = [50000, 100000, 150000, 200000];
    protected $depositMid   = [250000, 300000, 400000, 500000, 600000, 750000, 900000];
    protected $depositBig   = [1000000, 1500000, 2000000, 3000000, 5000000, 8000000, 12000000, 15000000];

    /**
     * Withdrawal amounts pool (IDR)
     */
    protected $withdrawSmall = [100000, 150000, 200000, 250000, 300000, 500000];
    protected $withdrawBig   = [750000, 1000000, 1500000, 2000000, 3000000, 5000000];

    /**
     * Latest possible activity date (before "today")
     */
    protected $endDate = '2026-08-21 18:00:00';

    protected $loginIps = [
        '36.70.215.34', '36.74.112.56', '110.137.194.179', '114.125.23.88', '118.99.123.10',
        '125.160.94.21', '140.213.142.56', '180.244.156.77', '182.1.45.210', '202.67.39.145',
        '103.119.108.62', '115.178.71.39', '114.79.18.201', '180.253.101.23',
    ];

    protected $loginCities = ['Jakarta', 'Surabaya', 'Bandung', 'Semarang', 'Yogyakarta', 'Medan', 'Makassar', 'Denpasar', 'Palembang', 'Tangerang', 'Bekasi', 'Depok', 'Bogor', 'Malang', 'Solo'];

    protected $browsers = ['Chrome', 'Firefox', 'Safari', 'Edge', 'Handheld Browser', 'Opera'];
    protected $osList   = ['Windows 10', 'Windows 11', 'Android', 'Mac OS X', 'iOS', 'Linux'];

    protected $rejectReasons = [
        'Nomor rekening tujuan tidak valid',
        'Nama pemilik rekening tidak sesuai',
        'Data KYC tidak lengkap',
        'Rekening terindikasi duplikat',
        'Nomor DANA tidak aktif',
        'Saldo sumber tidak mencukupi',
    ];

    protected $stats = ['users' => 0, 'deposits' => 0, 'withdrawals' => 0, 'transactions' => 0, 'logins' => 0];

    public function run()
    {
        $password = Hash::make('123456');
        $created  = [];

        foreach ($this->profiles as $index => $profile) {
            $username = $this->uniqueUsername($profile);
            $email    = $this->uniqueEmail($username);

            if (!$username || !$email) {
                $this->command->warn("Skipped {$profile['firstname']} {$profile['lastname']}: username/email already exists");
                continue;
            }

            $registeredAt = $this->randomDate('2026-06-03 08:00:00', '2026-08-12 20:00:00');
            $registeredTs = strtotime($registeredAt);
            $endTs        = strtotime($this->endDate);

            $bonus   = random_int(80, 240) * 100;
            $balance = $bonus;
            $lastTs  = $registeredTs;

            $userId = DB::table('users')->insertGetId([
                'firstname'        => $profile['firstname'],
                'lastname'         => $profile['lastname'],
                'username'         => $username,
                'email'            => $email,
                'dial_code'        => '+62',
                'country_code'     => 'ID',
                'mobile'           => $profile['mobile'],
                'image'            => null,
                'ref_by'           => null,
                'balance'          => $balance,
                'demo_balance'     => (random_int(0, 9) < 7) ? random_int(15000, 250000) : 0,
                'password'         => $password,
                'country_name'     => 'Indonesia',
                'city'             => $profile['city'],
                'state'            => $profile['state'],
                'zip'              => $profile['zip'],
                'address'          => 'Jl. ' . $profile['city'] . ' No. ' . random_int(1, 200),
                'status'           => 1,
                'ev'               => 1,
                'sv'               => 1,
                'ver_code'         => null,
                'ver_code_send_at' => null,
                'ts'               => 0,
                'tv'               => 1,
                'tsc'              => null,
                'remember_token'   => null,
                'provider'         => null,
                'provider_id'      => null,
                'kyc_data'         => null,
                'kv'               => 1,
                'profile_complete' => 1,
                'login_by'         => null,
                'created_at'       => $registeredAt,
                'updated_at'       => $registeredAt,
            ]);
            $this->stats['users']++;
            $created[] = $username;

            // Welcome bonus (register bonus)
            $this->transaction($userId, $bonus, 0, $balance, '+', $this->trxHex(), 'Welcome bonus pendaftaran', 'register_bonus', $registeredAt);

            // Build and process the financial timeline
            $events = $this->buildEvents($registeredTs, $endTs, $index);

            foreach ($events as $i => $event) {
                $eventTs = strtotime($event['date']);
                $lastTs  = max($lastTs, $eventTs);

                if ($event['type'] === 'deposit') {
                    $trx = $this->trx();
                    DB::table('deposits')->insert([
                        'user_id'         => $userId,
                        'method_code'     => 511, // RoguePay
                        'amount'          => $event['amount'],
                        'method_currency' => 'IDR',
                        'charge'          => 0,
                        'rate'            => 1,
                        'final_amount'    => $event['amount'],
                        'detail'          => null,
                        'btc_amount'      => null,
                        'btc_wallet'      => null,
                        'trx'             => $trx,
                        'payment_try'     => 0,
                        'status'          => $event['status'],
                        'from_api'        => 0,
                        'is_web'          => 0,
                        'admin_feedback'  => null,
                        'success_url'     => null,
                        'failed_url'      => null,
                        'last_cron'       => 0,
                        'created_at'      => $event['date'],
                        'updated_at'      => $event['date'],
                    ]);
                    $this->stats['deposits']++;

                    if ($event['status'] === 1) { // success -> credit balance
                        $balance += $event['amount'];
                        $this->transaction($userId, $event['amount'], 0, $balance, '+', $trx, 'Deposit Via RoguePay - IDR', 'deposit', $event['date']);
                    }
                } elseif ($event['type'] === 'withdraw') {
                    if ($balance < 100000) {
                        continue; // not enough balance at this point
                    }

                    $amount = $event['amount'];
                    if ($amount > $balance) {
                        $amount = (int) floor($balance * 0.7 / 1000) * 1000;
                    }
                    if ($amount < 50000) {
                        continue;
                    }

                    $trx       = $this->trx();
                    $balance  -= $amount;
                    $updatedAt = $event['date'];
                    $feedback  = null;
                    $refundAt  = null;

                    if ($event['status'] === 1) { // approved
                        $updatedAt = date('Y-m-d H:i:s', $eventTs + random_int(3600, 172800));
                    } elseif ($event['status'] === 3) { // rejected -> refund a bit later
                        $nextTs    = isset($events[$i + 1]) ? strtotime($events[$i + 1]['date']) : $endTs;
                        $refundAt  = date('Y-m-d H:i:s', min($nextTs - 60, $eventTs + random_int(300, 3600)));
                        $updatedAt = $refundAt;
                        $feedback  = $this->rejectReasons[array_rand($this->rejectReasons)];
                    }

                    DB::table('withdrawals')->insert([
                        'method_id'            => 1, // DANA
                        'user_id'              => $userId,
                        'amount'               => $amount,
                        'currency'             => 'IDR',
                        'rate'                 => 1,
                        'charge'               => 0,
                        'trx'                  => $trx,
                        'pg_ref'               => null,
                        'final_amount'         => $amount,
                        'after_charge'         => $amount,
                        'withdraw_information' => json_encode([
                            'dana_account' => 'A/N ' . $profile['firstname'] . ' ' . $profile['lastname'],
                            'dana_number'  => $profile['mobile'],
                        ]),
                        'status'           => $event['status'],
                        'admin_feedback'   => $feedback,
                        'created_at'       => $event['date'],
                        'updated_at'       => $updatedAt,
                    ]);
                    $this->stats['withdrawals']++;

                    $this->transaction($userId, $amount, 0, $balance, '-', $trx, 'Withdraw request via DANA', 'withdraw', $event['date']);

                    if ($refundAt) {
                        $balance += $amount;
                        $this->transaction($userId, $amount, 0, $balance, '+', $trx, 'Refunded for withdrawal rejection', 'withdraw_reject', $refundAt);
                    }
                } elseif ($event['type'] === 'topup') {
                    $trx     = $this->trxHex();
                    $balance += $event['amount'];
                    $this->transaction($userId, $event['amount'], 0, $balance, '+', $trx, 'Top-up admin (koreksi saldo)', 'balance_add', $event['date']);
                }
            }

            // Login logs (realistic Indonesian access history)
            foreach (range(1, random_int(4, 6)) as $j) {
                $loginAt = date('Y-m-d H:i:s', random_int($registeredTs, $endTs));
                DB::table('user_logins')->insert([
                    'user_id'      => $userId,
                    'user_ip'      => $this->loginIps[array_rand($this->loginIps)],
                    'city'         => $this->loginCities[array_rand($this->loginCities)],
                    'country'      => 'Indonesia',
                    'country_code' => 'ID',
                    'longitude'    => (string) random_int(9500, 14100) / 100,
                    'latitude'     => (string) (random_int(-850, 550) / 100),
                    'browser'      => $this->browsers[array_rand($this->browsers)],
                    'os'           => $this->osList[array_rand($this->osList)],
                    'created_at'   => $loginAt,
                    'updated_at'   => $loginAt,
                ]);
                $this->stats['logins']++;
            }

            // Final balance + last activity
            DB::table('users')->where('id', $userId)->update([
                'balance'    => $balance,
                'updated_at' => date('Y-m-d H:i:s', $lastTs),
            ]);
        }

        $this->command->info('--- Seed complete ---');
        $this->command->info('Users: ' . $this->stats['users'] . ' | Deposits: ' . $this->stats['deposits'] . ' | Withdrawals: ' . $this->stats['withdrawals'] . ' | Transactions: ' . $this->stats['transactions'] . ' | Logins: ' . $this->stats['logins']);
        $this->command->info('Login password for all new users: 123456');
        $this->command->info('New usernames: ' . implode(', ', $created));
    }

    /**
     * Build a chronological event list (deposits, withdrawals, admin top-ups)
     */
    protected function buildEvents($registeredTs, $endTs, $userIndex)
    {
        $deposits = $this->depositEvents($registeredTs, $endTs);
        $withdraw = $this->withdrawEvents($registeredTs, $endTs);
        $topups   = [];

        // A couple of users get an admin balance correction
        if (in_array($userIndex, [2, 7])) {
            $topups[] = [
                'type'   => 'topup',
                'amount' => random_int(50, 250) * 10000,
                'status' => null,
                'date'   => $this->randomDateTs($registeredTs + 3 * 86400, $endTs - 86400),
            ];
        }

        $events = array_merge($deposits, $withdraw, $topups);
        usort($events, fn ($a, $b) => strtotime($a['date']) <=> strtotime($b['date']));

        // Make sure the first event is a deposit so the user has balance
        $firstDeposit = null;
        foreach ($events as $i => $ev) {
            if ($ev['type'] === 'deposit') {
                $firstDeposit = $i;
                break;
            }
        }
        if ($firstDeposit !== null && $events[0]['type'] !== 'deposit') {
            $tmp           = $events[0]['date'];
            $events[0]['date'] = $events[$firstDeposit]['date'];
            $events[$firstDeposit]['date'] = $tmp;
            usort($events, fn ($a, $b) => strtotime($a['date']) <=> strtotime($b['date']));
        }

        return $events;
    }

    protected function depositEvents($registeredTs, $endTs)
    {
        $count   = random_int(3, 6);
        $amounts = [$this->depositSmall[array_rand($this->depositSmall)], $this->depositBig[array_rand($this->depositBig)]];

        for ($i = 2; $i < $count; $i++) {
            $pool = $this->depositMid;
            if (random_int(0, 1)) {
                $pool = array_merge($pool, $this->depositSmall, $this->depositBig);
            }
            $amounts[] = $pool[array_rand($pool)];
        }
        shuffle($amounts);

        $events = [];
        foreach ($amounts as $amount) {
            $events[] = [
                'type'   => 'deposit',
                'amount' => $amount,
                'status' => $this->weighted([1 => 60, 2 => 25, 3 => 15]),
                'date'   => $this->randomDateTs($registeredTs + 86400, $endTs - 2 * 86400),
            ];
        }

        // Guarantee at least 2 successful deposits
        $success = count(array_filter($events, fn ($e) => $e['status'] === 1));
        foreach ($events as &$ev) {
            if ($success >= 2) {
                break;
            }
            if ($ev['status'] !== 1) {
                $ev['status'] = 1;
                $success++;
            }
        }
        unset($ev);

        return $events;
    }

    protected function withdrawEvents($registeredTs, $endTs)
    {
        $count = random_int(2, 4);
        $amounts = [$this->withdrawSmall[array_rand($this->withdrawSmall)], $this->withdrawBig[array_rand($this->withdrawBig)]];

        for ($i = 2; $i < $count; $i++) {
            $pool = array_merge($this->withdrawSmall, $this->withdrawBig);
            $amounts[] = $pool[array_rand($pool)];
        }
        shuffle($amounts);

        $statuses = [1, 2]; // at least one approved + one pending
        for ($i = 2; $i < $count; $i++) {
            $statuses[] = $this->weighted([1 => 35, 2 => 40, 3 => 25]);
        }
        shuffle($statuses);

        $events = [];
        foreach ($amounts as $i => $amount) {
            $events[] = [
                'type'   => 'withdraw',
                'amount' => $amount,
                'status' => $statuses[$i],
                'date'   => $this->randomDateTs($registeredTs + 2 * 86400, $endTs),
            ];
        }

        return $events;
    }

    /**
     * Insert a transaction row (post_balance is the running balance)
     */
    protected function transaction($userId, $amount, $charge, $postBalance, $type, $trx, $details, $remark, $date)
    {
        DB::table('transactions')->insert([
            'user_id'      => $userId,
            'amount'       => $amount,
            'charge'       => $charge,
            'post_balance' => $postBalance,
            'trx_type'     => $type,
            'trx'          => $trx,
            'details'      => $details,
            'remark'       => $remark,
            'created_at'   => $date,
            'updated_at'   => $date,
        ]);
        $this->stats['transactions']++;
    }

    /**
     * Unique username: 4 first + 4 last chars + 2 digits (same style as existing users)
     */
    protected function uniqueUsername($profile)
    {
        $base = strtolower(substr($profile['firstname'], 0, 4) . substr($profile['lastname'], 0, 4));

        for ($attempt = 0; $attempt < 20; $attempt++) {
            $username = $base . random_int(10, 99);
            if (!DB::table('users')->where('username', $username)->exists()) {
                return $username;
            }
        }

        return null;
    }

    protected function uniqueEmail($username)
    {
        for ($attempt = 0; $attempt < 20; $attempt++) {
            $domain = (random_int(0, 1) ? 'gmail.com' : 'yahoo.com');
            $email  = $username . random_int(0, 9) . '@' . $domain;
            if (!DB::table('users')->where('email', $email)->exists()) {
                return $email;
            }
        }

        return null;
    }

    protected function randomDate($from, $to)
    {
        return date('Y-m-d H:i:s', random_int(strtotime($from), strtotime($to)));
    }

    protected function randomDateTs($fromTs, $toTs)
    {
        return date('Y-m-d H:i:s', random_int($fromTs, max($fromTs, $toTs)));
    }

    protected function weighted(array $weights)
    {
        $total = array_sum($weights);
        $roll  = random_int(1, $total);

        foreach ($weights as $status => $weight) {
            if ($roll <= $weight) {
                return $status;
            }
            $roll -= $weight;
        }

        return array_key_first($weights);
    }

    protected function trx()
    {
        // Same character set as the app's getTrx()
        $chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ123456789';
        $trx   = '';
        for ($i = 0; $i < 12; $i++) {
            $trx .= $chars[random_int(0, strlen($chars) - 1)];
        }

        return $trx;
    }

    protected function trxHex()
    {
        return strtoupper(bin2hex(random_bytes(8)));
    }
}
