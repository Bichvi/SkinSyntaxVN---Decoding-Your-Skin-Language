
const email = '18c12003@gmail.com';
const hoTen = 'Phạm Thị Xuân Hiển';
const hash = '$2b$10$fr3r331TSbwgehv7FHzjdeLf383VQ.dkI4wpIY2EoCcCXsgp6DmgG';
const now = new Date();

db.nguoidung.deleteMany({ email: { $regex: /^18c12003@gmail\.com$/i } });
db.khach_hang.deleteMany({ email: { $regex: /^18c12003@gmail\.com$/i } });

db.nguoidung.insertOne({
  id: 1,
  ho_ten: hoTen,
  email: email,
  mat_khau: hash,
  created_at: now,
});

const lastKh = db.khach_hang.find().sort({ ma_kh: -1 }).limit(1).toArray()[0];
const maKh = lastKh && lastKh.ma_kh ? Number(lastKh.ma_kh) + 1 : 1;

db.khach_hang.insertOne({
  ma_kh: maKh,
  ho_ten: hoTen,
  email: email,
  gioi_tinh: 'Nữ',
  created_at: now,
  updated_at: now,
});

printjson({
  ok: true,
  email: email,
  password: 'Hien1987@',
  ma_kh: maKh,
});
