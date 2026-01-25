<?php
// backend/app/models/NguoiDung.php

class NguoiDung {
    private PDO $pdo;

    public function __construct(PDO $pdo) {
        $this->pdo = $pdo;
    }

    public function timTheoEmail(string $email): ?array {
        $sql = "SELECT * FROM nguoidung WHERE email = :email LIMIT 1";
        $st = $this->pdo->prepare($sql);
        $st->execute([':email' => $email]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function taoMoi(string $hoTen, string $email, string $matKhauPlain): bool {
        $hash = password_hash($matKhauPlain, PASSWORD_BCRYPT);

        $sql = "INSERT INTO nguoidung(ho_ten, email, mat_khau) VALUES (:ho_ten, :email, :mat_khau)";
        $st = $this->pdo->prepare($sql);
        return $st->execute([
            ':ho_ten' => $hoTen,
            ':email' => $email,
            ':mat_khau' => $hash
        ]);
    }
}
