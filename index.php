<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('error_log', 'error.log');

// Atur zona waktu ke Asia/Jakarta (GMT+7)
date_default_timezone_set('Asia/Jakarta');

require_once 'ResponWebhookFormatter.php';
header('content-type: application/json; charset=utf-8');
$data = json_decode(file_get_contents('php://input'), true);
if (!$data) die('this url is for webhook.');

file_put_contents('whatsapp.txt', '[' . date('Y-m-d H:i:s') . "]\n" . json_encode($data) . "\n\n", FILE_APPEND);
$message = strtolower($data['message']); // pesan masuk dari whatsapp
$from = strtolower($data['from']); // nomor pengirim
$bufferimage = isset($data['bufferImage']) ? $data['bufferImage'] : null; // buffer gambar jika ada

$respon = false;
$responFormatter = new ResponWebhookFormater();

// Konfigurasi database
$dbConfig = [
    'host' => 'localhost',
    'username' => '<user>',
    'password' => '<password>',
    'database' => '<db>'
];

// Koneksi ke database
function connectDB($config) {
    $conn = new mysqli($config['host'], $config['username'], $config['password'], $config['database']);
    if ($conn->connect_error) {
        error_log("Koneksi database gagal: " . $conn->connect_error);
        return null;
    }
    return $conn;
}

// Fungsi untuk menyimpan kota default pengguna
function saveUserCity($phone, $city) {
    global $dbConfig;
    $conn = connectDB($dbConfig);
    if (!$conn) return false;
    
    // Cek apakah pengguna sudah ada
    $stmt = $conn->prepare("SELECT id FROM user_preferences WHERE phone = ?");
    $stmt->bind_param("s", $phone);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows > 0) {
        // Update data yang sudah ada
        $stmt = $conn->prepare("UPDATE user_preferences SET default_city = ?, updated_at = NOW() WHERE phone = ?");
        $stmt->bind_param("ss", $city, $phone);
    } else {
        // Insert data baru
        $stmt = $conn->prepare("INSERT INTO user_preferences (phone, default_city, created_at, updated_at) VALUES (?, ?, NOW(), NOW())");
        $stmt->bind_param("ss", $phone, $city);
    }
    
    $success = $stmt->execute();
    $stmt->close();
    $conn->close();
    return $success;
}

// Fungsi untuk mendapatkan kota default pengguna
function getUserCity($phone) {
    global $dbConfig;
    $conn = connectDB($dbConfig);
    if (!$conn) return null;
    
    $stmt = $conn->prepare("SELECT default_city FROM user_preferences WHERE phone = ?");
    $stmt->bind_param("s", $phone);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows > 0) {
        $row = $result->fetch_assoc();
        $city = $row['default_city'];
        $stmt->close();
        $conn->close();
        return $city;
    }
    
    $stmt->close();
    $conn->close();
    return null;
}

// Fungsi untuk mengambil data jadwal sholat
function getJadwalSholat($kota) {
    $url = "https://script.google.com/macros/s/AKfycbx8CtuEFQrYxM5sF2pZYvjrcIQa4Mj25lO6BUVqFHrhURw05bg06dBtpeYtvax5NIi1/exec?kota=" . urlencode($kota);
    $response = file_get_contents($url);
    return json_decode($response, true);
}

// Fungsi untuk mendapatkan daftar kota
function getDaftarKota() {
    $url = "https://script.google.com/macros/s/AKfycbx8CtuEFQrYxM5sF2pZYvjrcIQa4Mj25lO6BUVqFHrhURw05bg06dBtpeYtvax5NIi1/exec?action=daftar-kota";
    $response = file_get_contents($url);
    return json_decode($response, true);
}

// Fungsi untuk validasi kota
function validateCity($city) {
    $daftarKota = getDaftarKota();
    if ($daftarKota['status'] === 'success') {
        foreach ($daftarKota['data'] as $kota) {
            if (strtolower($kota['name']) === strtolower($city)) {
                return $kota['name'];
            }
        }
    }
    return false;
}

// Command /jadwal <namaKota>
if (strpos($message, '/jadwal') === 0) {
    $messageArray = explode(' ', $message, 2);
    
    // Jika tidak ada parameter kota
    if (count($messageArray) < 2) {
        // Cek apakah pengguna punya kota default
        $defaultCity = getUserCity($from);
        
        if ($defaultCity) {
            $jadwal = getJadwalSholat($defaultCity);
            
            if ($jadwal['status'] === 'success') {
                $data = $jadwal['data'];
                $responFormatter->bold('🕌 Jadwal Sholat ' . $data['kota'])
                    ->line('📅 ' . $data['tanggal'])
                    ->line('📱 Kota default Anda')
                    ->line('')
                    ->bold('⏰ Jadwal:')
                    ->line('• Imsyak: ' . $data['jadwal']['imsyak'] . ' WIB')
                    ->line('• Subuh: ' . $data['jadwal']['shubuh'] . ' WIB')
                    ->line('• Terbit: ' . $data['jadwal']['terbit'] . ' WIB')
                    ->line('• Dhuha: ' . $data['jadwal']['dhuha'] . ' WIB')
                    ->line('• Dzuhur: ' . $data['jadwal']['dzuhur'] . ' WIB')
                    ->line('• Ashar: ' . $data['jadwal']['ashr'] . ' WIB')
                    ->line('• Maghrib: ' . $data['jadwal']['magrib'] . ' WIB')
                    ->line('• Isya: ' . $data['jadwal']['isya'] . ' WIB')
                    ->line('')
                    ->italic('Semoga ibadah Anda lancar!')
                    ->footer('Jadwal Sholat Bot');
                
                // Tambahkan tombol untuk aksi cepat
                $responFormatter->addButton("Menu Utama")
                    ->addButton("Jadwal Besok")
                    ->addButton("Ubah Kota Default");
                    
                $respon = $responFormatter->responAsButton();
            } else {
                $respon = $responFormatter->line('❌ *Terjadi kesalahan*')
                    ->line('Gagal mendapatkan jadwal sholat untuk kota default Anda.')
                    ->line('Silahkan coba atur ulang kota default dengan perintah:')
                    ->line('/setkota <nama_kota>')
                    ->responAsText();
            }
        } else {
            $respon = $responFormatter->bold('⏰ Jadwal Sholat')
                ->line('Silahkan masukkan nama kota:')
                ->line('Contoh: /jadwal kediri')
                ->line('')
                ->line('Untuk menyimpan kota default:')
                ->line('/setkota <nama_kota>')
                ->line('')
                ->line('Untuk melihat daftar kota yang tersedia:')
                ->line('/carikota <nama_kota>')
                ->responAsText();
        }
    } else {
        $kota = trim($messageArray[1]);
        $jadwal = getJadwalSholat($kota);
        
        if ($jadwal['status'] === 'success') {
            $data = $jadwal['data'];
            $responFormatter->bold('🕌 Jadwal Sholat ' . $data['kota'])
                ->line('📅 ' . $data['tanggal'])
                ->line('')
                ->bold('⏰ Jadwal:')
                ->line('• Imsyak: ' . $data['jadwal']['imsyak'] . ' WIB')
                ->line('• Subuh: ' . $data['jadwal']['shubuh'] . ' WIB')
                ->line('• Terbit: ' . $data['jadwal']['terbit'] . ' WIB')
                ->line('• Dhuha: ' . $data['jadwal']['dhuha'] . ' WIB')
                ->line('• Dzuhur: ' . $data['jadwal']['dzuhur'] . ' WIB')
                ->line('• Ashar: ' . $data['jadwal']['ashr'] . ' WIB')
                ->line('• Maghrib: ' . $data['jadwal']['magrib'] . ' WIB')
                ->line('• Isya: ' . $data['jadwal']['isya'] . ' WIB')
                ->line('')
                ->italic('Semoga ibadah Anda lancar!')
                ->footer('Jadwal Sholat Bot');
            
            // Tambahkan tombol untuk aksi cepat
            $responFormatter->addButton("Menu Utama")
                ->addButton("Jadwal Besok")
                ->addButton("Jadikan Kota Default");
                
            $respon = $responFormatter->responAsButton();
        } else {
            $responFormatter->line('❌ *Kota tidak ditemukan*')
                ->line('Kota "' . $kota . '" tidak tersedia dalam database.')
                ->line('')
                ->line('Silahkan cari kota dengan perintah:')
                ->line('/carikota <sebagian_nama_kota>')
                ->line('')
                ->line('Contoh: /carikota kediri');
                
            $respon = $responFormatter->responAsText();
        }
    }
}

// Command /setkota <namaKota>
if (strpos($message, '/setkota') === 0) {
    $messageArray = explode(' ', $message, 2);
    
    if (count($messageArray) < 2) {
        $respon = $responFormatter->bold('⚙️ Atur Kota Default')
            ->line('Silahkan masukkan nama kota yang ingin dijadikan default:')
            ->line('Contoh: /setkota kediri')
            ->responAsText();
    } else {
        $kota = trim($messageArray[1]);
        
        // Validasi kota
        $validCity = validateCity($kota);
        
        if ($validCity) {
            // Simpan ke database
            if (saveUserCity($from, $validCity)) {
                $respon = $responFormatter->bold('✅ Kota Default Berhasil Disimpan')
                    ->line('Kota default Anda sekarang adalah: *' . ucfirst($validCity) . '*')
                    ->line('')
                    ->line('Anda sekarang bisa cek jadwal dengan mengetik:')
                    ->line('/jadwal (tanpa parameter kota)')
                    ->responAsText();
            } else {
                $respon = $responFormatter->bold('❌ Gagal Menyimpan Kota Default')
                    ->line('Terjadi kesalahan saat menyimpan data. Silahkan coba lagi nanti.')
                    ->responAsText();
            }
        } else {
            $respon = $responFormatter->bold('❌ Kota Tidak Valid')
                ->line('Kota "' . $kota . '" tidak ditemukan dalam database.')
                ->line('')
                ->line('Silahkan cari kota yang valid dengan perintah:')
                ->line('/carikota <sebagian_nama_kota>')
                ->responAsText();
        }
    }
}

// Command /carikota <keyword>
if (strpos($message, '/carikota') === 0) {
    $messageArray = explode(' ', $message, 2);
    if (count($messageArray) < 2) {
        $respon = $responFormatter->bold('🔍 Cari Kota')
            ->line('Silahkan masukkan kata kunci nama kota:')
            ->line('Contoh: /carikota kediri')
            ->responAsText();
    } else {
        $keyword = strtolower(trim($messageArray[1]));
        $daftarKota = getDaftarKota();
        
        if ($daftarKota['status'] === 'success') {
            $kotaList = $daftarKota['data'];
            $hasilPencarian = [];
            
            foreach ($kotaList as $kota) {
                if (strpos(strtolower($kota['name']), $keyword) !== false) {
                    $hasilPencarian[] = $kota['name'];
                }
                
                // Batasi hasil pencarian ke 20 kota untuk menghindari pesan terlalu panjang
                if (count($hasilPencarian) >= 20) {
                    break;
                }
            }
            
            if (count($hasilPencarian) > 0) {
                $responFormatter->bold('🔍 Hasil Pencarian Kota: "' . $keyword . '"')
                    ->line('Ditemukan ' . count($hasilPencarian) . ' kota:')
                    ->line('');
                
                foreach ($hasilPencarian as $index => $namaKota) {
                    $responFormatter->line(($index + 1) . '. ' . ucfirst($namaKota));
                }
                
                $responFormatter->line('')
                    ->line('Untuk melihat jadwal, ketik:')
                    ->line('/jadwal <nama_kota>')
                    ->line('')
                    ->line('Untuk menjadikan sebagai kota default:')
                    ->line('/setkota <nama_kota>')
                    ->line('')
                    ->italic('Contoh: /jadwal ' . $hasilPencarian[0]);
                
                $respon = $responFormatter->responAsText();
            } else {
                $respon = $responFormatter->bold('🔍 Hasil Pencarian Kota: "' . $keyword . '"')
                    ->line('Maaf, tidak ditemukan kota dengan kata kunci tersebut.')
                    ->line('')
                    ->line('Silahkan coba dengan kata kunci lain.')
                    ->responAsText();
            }
        } else {
            $respon = $responFormatter->line('❌ *Terjadi kesalahan*')
                ->line('Gagal mendapatkan daftar kota.')
                ->line('Silahkan coba lagi nanti.')
                ->responAsText();
        }
    }
}

// Command /info
if ($message === '/info') {
    $responFormatter->bold('ℹ️ Jadwal Sholat Bot')
        ->line('Bot untuk mengecek jadwal sholat di seluruh Indonesia')
        ->line('')
        ->bold('📋 Daftar Perintah:')
        ->line('• /jadwal <nama_kota>')
        ->line('  Untuk melihat jadwal sholat di kota tertentu')
        ->line('')
        ->line('• /carikota <kata_kunci>')
        ->line('  Untuk mencari kota yang tersedia')
        ->line('')
        ->line('• /setkota <nama_kota>')
        ->line('  Untuk menyimpan kota default')
        ->line('')
        ->line('• /bandingkan <kota1> <kota2>')
        ->line('  Untuk membandingkan jadwal 2 kota')
        ->line('')
        ->line('• /info')
        ->line('  Menampilkan info bot dan daftar perintah')
        ->line('')
        ->line('• /waktu')
        ->line('  Melihat waktu sholat yang akan datang')
        ->line('')
        ->italic('Dibuat dengan ❤️ untuk memudahkan ibadah')
        ->footer('Jadwal Sholat Bot © ' . date('Y'));
        
    $responFormatter->addTemplateButton("🌐 Sumber Data", "https://s.id/api-sholat")
        ->addTemplateButton("☎️ Kontak Admin", "6281241314446", "call");
        
    $respon = $responFormatter->responAsTemplateButton();
}

// Command /waktu - Menampilkan waktu sholat selanjutnya
if ($message === '/waktu') {
    // Gunakan kota default jika ada
    $kota = getUserCity($from) ?: "jakarta";
    $jadwal = getJadwalSholat($kota);
    
    if ($jadwal['status'] === 'success') {
        $data = $jadwal['data'];
        $jadwalHariIni = $data['jadwal'];
        
        // Konversi ke timestamp untuk perbandingan
        $waktuSekarang = time();
        $waktuSholat = [
            'Subuh' => strtotime(date('Y-m-d') . ' ' . $jadwalHariIni['shubuh']),
            'Dzuhur' => strtotime(date('Y-m-d') . ' ' . $jadwalHariIni['dzuhur']),
            'Ashar' => strtotime(date('Y-m-d') . ' ' . $jadwalHariIni['ashr']),
            'Maghrib' => strtotime(date('Y-m-d') . ' ' . $jadwalHariIni['magrib']),
            'Isya' => strtotime(date('Y-m-d') . ' ' . $jadwalHariIni['isya'])
        ];
        
        // Temukan waktu sholat selanjutnya
        $sholatSelanjutnya = null;
        $waktuSelanjutnya = null;
        
        foreach ($waktuSholat as $namaSholat => $waktu) {
            if ($waktu > $waktuSekarang) {
                $sholatSelanjutnya = $namaSholat;
                $waktuSelanjutnya = $waktu;
                break;
            }
        }
        
        // Jika semua waktu sholat hari ini sudah lewat, tampilkan Subuh besok
        if ($sholatSelanjutnya === null) {
            $sholatSelanjutnya = 'Subuh (besok)';
            $waktuSelanjutnya = strtotime(date('Y-m-d', strtotime('+1 day')) . ' ' . $jadwalHariIni['shubuh']);
        }
        
        // Hitung selisih waktu
        $selisih = $waktuSelanjutnya - $waktuSekarang;
        $jam = floor($selisih / 3600);
        $menit = floor(($selisih % 3600) / 60);
        
        $responFormatter->bold('⏰ Waktu Sholat Selanjutnya')
            ->line('📍 Kota: ' . $data['kota'])
            ->line('📅 ' . $data['tanggal'])
            ->line('')
            ->bold('🕌 ' . $sholatSelanjutnya . ': ' . date('H:i', $waktuSelanjutnya) . ' WIB')
            ->line('⏱️ ' . $jam . ' jam ' . $menit . ' menit lagi')
            ->line('')
            ->line('Untuk melihat jadwal lengkap:')
            ->line('/jadwal ' . strtolower($data['kota']))
            ->footer('Jadwal Sholat Bot');
            
        $responFormatter->addButton("Menu Utama")
            ->addButton("Jadwal Lengkap");
            
        $respon = $responFormatter->responAsButton();
    } else {
        $respon = $responFormatter->line('❌ *Terjadi kesalahan*')
            ->line('Gagal mendapatkan jadwal sholat.')
            ->line('Silahkan coba lagi nanti.')
            ->responAsText();
    }
}

// Command /bandingkan <kota1> <kota2>
if (strpos($message, '/bandingkan') === 0) {
    $parts = explode(' ', $message, 3);
    
    if (count($parts) < 3) {
        $respon = $responFormatter->bold('📊 Perbandingan Jadwal Sholat')
            ->line('Gunakan format: /bandingkan <kota1> <kota2>')
            ->line('')
            ->line('Contoh: /bandingkan jakarta surabaya')
            ->responAsText();
    } else {
        $kota1 = trim($parts[1]);
        $kota2 = trim($parts[2]);
        
        $jadwal1 = getJadwalSholat($kota1);
        $jadwal2 = getJadwalSholat($kota2);
        
        if ($jadwal1['status'] === 'success' && $jadwal2['status'] === 'success') {
            $data1 = $jadwal1['data'];
            $data2 = $jadwal2['data'];
            
            $responFormatter->bold('📊 Perbandingan Jadwal Sholat')
                ->line('📅 ' . $data1['tanggal'])
                ->line('')
                ->bold('⏰ ' . ucfirst($data1['kota']) . ' vs ' . ucfirst($data2['kota']) . ':')
                ->line('');
            
            // Bandingkan setiap waktu sholat
            $waktuSholat = [
                'Imsyak' => ['imsyak', 'imsyak'],
                'Subuh' => ['shubuh', 'shubuh'],
                'Terbit' => ['terbit', 'terbit'],
                'Dhuha' => ['dhuha', 'dhuha'],
                'Dzuhur' => ['dzuhur', 'dzuhur'],
                'Ashar' => ['ashr', 'ashr'],
                'Maghrib' => ['magrib', 'magrib'],
                'Isya' => ['isya', 'isya']
            ];
            
            foreach ($waktuSholat as $nama => $keys) {
                $waktu1 = $data1['jadwal'][$keys[0]];
                $waktu2 = $data2['jadwal'][$keys[1]];
                
                // Hitung selisih waktu dalam menit
                $time1 = strtotime(date('Y-m-d') . ' ' . $waktu1);
                $time2 = strtotime(date('Y-m-d') . ' ' . $waktu2);
                $selisihMenit = abs($time1 - $time2) / 60;
                
                $selisihText = '';
                if ($time1 < $time2) {
                    $selisihText = ' (' . ucfirst($data1['kota']) . ' lebih awal ' . $selisihMenit . ' menit)';
                } elseif ($time1 > $time2) {
                    $selisihText = ' (' . ucfirst($data2['kota']) . ' lebih awal ' . $selisihMenit . ' menit)';
                }
                
                $responFormatter->line('• ' . $nama . ': ' . $waktu1 . ' vs ' . $waktu2 . $selisihText);
            }
            
            $responFormatter->line('')
                ->line('Informasi ini berguna bagi musafir yang bepergian antar kota.')
                ->footer('Jadwal Sholat Bot');
                
            $respon = $responFormatter->responAsText();
        } else {
            $errorMsg = '';
            if ($jadwal1['status'] !== 'success') {
                $errorMsg .= 'Kota "' . $kota1 . '" tidak ditemukan. ';
            }
            if ($jadwal2['status'] !== 'success') {
                $errorMsg .= 'Kota "' . $kota2 . '" tidak ditemukan. ';
            }
            
            $respon = $responFormatter->bold('❌ Kota Tidak Ditemukan')
                ->line($errorMsg)
                ->line('')
                ->line('Silahkan cari kota yang valid dengan perintah:')
                ->line('/carikota <nama_kota>')
                ->responAsText();
        }
    }
}

// Keyword umum dan salam
$keywords = [
    'assalamualaikum' => '🌙 Wa\'alaikumussalam Warahmatullahi Wabarakatuh',
    'asalamualaikum' => '🌙 Wa\'alaikumussalam Warahmatullahi Wabarakatuh',
    'assalamu\'alaikum' => '🌙 Wa\'alaikumussalam Warahmatullahi Wabarakatuh',
    'halo' => 'Halo! Ada yang bisa saya bantu?\nKetik /info untuk melihat daftar perintah',
    'hi' => 'Hi! Silahkan ketik /info untuk bantuan',
    'hello' => 'Hello! Silahkan ketik /info untuk bantuan'
];

foreach ($keywords as $key => $value) {
    if ($message === $key || strpos($message, $key) === 0) {
        $respon = $responFormatter->line($value)->responAsText();
        break;
    }
}

// Pesan default jika perintah tidak dikenali
if (!$respon && $message && $message[0] === '/') {
    $respon = $responFormatter->line('❌ *Perintah tidak dikenali*')
        ->line('Silahkan ketik /info untuk melihat daftar perintah yang tersedia.')
        ->responAsText();
}

// Save respon to file
if ($respon) {
    file_put_contents('respon.txt', '[' . date('Y-m-d H:i:s') . "]\n" . $respon . "\n\n", FILE_APPEND);
}

echo $respon;
?>
