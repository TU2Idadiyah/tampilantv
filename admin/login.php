<?php
require_once __DIR__ . '/auth.php';

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = $_POST['email'] ?? '';
    $password = $_POST['password'] ?? '';

    $login = adminLogin($email, $password);
    if ($login['sukses']) {
        header("Location: index.php");
        exit;
    } else {
        $error = $login['pesan'];
    }
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <title>Login Admin - STAI Sidogiri</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <style>
        :root { --g: #009B4C; --g-dark: #006b34; --bg: #f3f8f5; --card: #fff; --ink: #16261d; --line: #dfeae3; }
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; background: var(--bg); color: var(--ink); display: flex; justify-content: center; align-items: center; min-height: 100vh; padding: 20px; }
        .card { width: 100%; max-width: 400px; background: var(--card); border-radius: 20px; padding: 32px 28px; box-shadow: 0 10px 30px rgba(0,155,76,0.08); border: 1px solid var(--line); }
        .logo { width: 56px; height: 56px; background: linear-gradient(135deg, var(--g), #37c47a); color: #fff; font-size: 22px; font-weight: 800; border-radius: 14px; display: grid; place-items: center; margin: 0 auto 12px; }
        h1 { font-size: 20px; text-align: center; color: var(--g-dark); }
        p.sub { font-size: 13px; text-align: center; color: #62756a; margin-bottom: 24px; margin-top: 4px; }
        .form-group { margin-bottom: 16px; }
        label { display: block; font-size: 13px; font-weight: 600; margin-bottom: 6px; }
        input { width: 100%; height: 44px; padding: 0 14px; border: 2px solid var(--line); border-radius: 10px; font-size: 14px; outline: none; }
        input:focus { border-color: var(--g); }
        button { width: 100%; height: 46px; background: var(--g); color: #fff; border: 0; border-radius: 10px; font-weight: 700; font-size: 15px; cursor: pointer; margin-top: 8px; }
        button:hover { opacity: 0.95; }
        .alert { background: #fee2e2; color: #991b1b; padding: 10px 14px; border-radius: 10px; font-size: 13px; margin-bottom: 16px; font-weight: 600; }
        .hint { font-size: 12px; color: #62756a; text-align: center; margin-top: 20px; line-height: 1.4; }
    </style>
</head>
<body>
    <div class="card">
        <div class="logo">STAI</div>
        <h1>Panel Admin</h1>
        <p class="sub">Sistem Informasi Layar Kampus STAI Sidogiri</p>

        <?php if ($error): ?>
            <div class="alert"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <form method="POST" id="loginForm">
            <div class="form-group">
                <label>Alamat Email</label>
                <input type="email" name="email" id="inputEmail" value="superadmin@stai-sidogiri.ac.id" required>
            </div>
            <div class="form-group">
                <label>Kata Sandi</label>
                <input type="password" name="password" id="inputPassword" value="password123" required>
            </div>
            <button type="submit">Masuk ke Panel</button>
        </form>

        <div style="margin-top:20px;padding-top:16px;border-top:1px dashed var(--line);text-align:center">
            <p style="font-size:12px;font-weight:700;color:#62756a;margin-bottom:8px">PILIHAN MASUK CEPAT (UNTUK SIAPA SAJA):</p>
            <div style="display:flex;gap:6px;justify-content:center;flex-wrap:wrap">
                <button type="button" onclick="setRole('superadmin@stai-sidogiri.ac.id', 'password123')" style="width:auto;height:auto;padding:6px 12px;font-size:12px;background:#e6f6ed;color:var(--g-dark);border:1px solid #bbf7d0;margin:0">
                    👑 Super Admin
                </button>
                <button type="button" onclick="setRole('operator@stai-sidogiri.ac.id', 'password123')" style="width:auto;height:auto;padding:6px 12px;font-size:12px;background:#e0f2fe;color:#0369a1;border:1px solid #bae6fd;margin:0">
                    🛠️ Operator
                </button>
            </div>
        </div>

        <div style="margin-top:14px;padding-top:12px;border-top:1px solid var(--line);display:flex;flex-direction:column;gap:6px">
            <a href="../dosen/index.php" style="font-size:13px;font-weight:700;color:var(--g-dark);text-decoration:none;display:flex;align-items:center;justify-content:center;gap:6px;padding:8px;background:var(--bg);border-radius:8px">
                <span>👨‍🏫 Masuk Portal Dosen (Hadir Online/Offline)</span> &rarr;
            </a>
            <a href="../absen/index.php" style="font-size:13px;font-weight:700;color:#0369a1;text-decoration:none;display:flex;align-items:center;justify-content:center;gap:6px;padding:8px;background:#f0f9ff;border-radius:8px">
                <span>📱 Masuk Presensi Pengurus (Web HP)</span> &rarr;
            </a>
            <a href="../login.php" style="font-size:12px;font-weight:700;color:var(--g-dark);text-decoration:none;text-align:center;padding:4px">
                🚪 Kembali ke Pusat Portal
            </a>
            <a href="../index.html" style="font-size:12px;font-weight:600;color:#62756a;text-decoration:none;text-align:center;padding:4px">
                📺 Pratinjau Layar TV Kampus (Tanpa Login)
            </a>
        </div>
    </div>

    <script>
    function setRole(email, pass) {
        document.getElementById('inputEmail').value = email;
        document.getElementById('inputPassword').value = pass;
        document.getElementById('loginForm').submit();
    }
    </script>
</body>
</html>

