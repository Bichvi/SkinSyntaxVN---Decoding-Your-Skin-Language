--
-- PostgreSQL database dump
--

\restrict 3aqwpHYBf7oGCk15oUbwn9vyGpEMDQV1S8cYfI3I7ozj3evTT4tb3HonldQKtVd

-- Dumped from database version 18.1
-- Dumped by pg_dump version 18.1

-- Started on 2026-01-25 14:15:39

SET statement_timeout = 0;
SET lock_timeout = 0;
SET idle_in_transaction_session_timeout = 0;
SET transaction_timeout = 0;
SET client_encoding = 'UTF8';
SET standard_conforming_strings = on;
SELECT pg_catalog.set_config('search_path', '', false);
SET check_function_bodies = false;
SET xmloption = content;
SET client_min_messages = warning;
SET row_security = off;

SET default_tablespace = '';

SET default_table_access_method = heap;

--
-- TOC entry 231 (class 1259 OID 16591)
-- Name: aichatbot; Type: TABLE; Schema: public; Owner: postgres
--

CREATE TABLE public.aichatbot (
    id integer NOT NULL,
    modelversion text,
    mota text
);


ALTER TABLE public.aichatbot OWNER TO postgres;

--
-- TOC entry 230 (class 1259 OID 16590)
-- Name: aichatbot_id_seq; Type: SEQUENCE; Schema: public; Owner: postgres
--

CREATE SEQUENCE public.aichatbot_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.aichatbot_id_seq OWNER TO postgres;

--
-- TOC entry 5165 (class 0 OID 0)
-- Dependencies: 230
-- Name: aichatbot_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: postgres
--

ALTER SEQUENCE public.aichatbot_id_seq OWNED BY public.aichatbot.id;


--
-- TOC entry 241 (class 1259 OID 17237)
-- Name: chitietdonhang; Type: TABLE; Schema: public; Owner: postgres
--

CREATE TABLE public.chitietdonhang (
    id bigint NOT NULL,
    donhang_id bigint NOT NULL,
    sanpham_id bigint NOT NULL,
    so_luong integer DEFAULT 1 NOT NULL,
    don_gia bigint DEFAULT 0
);


ALTER TABLE public.chitietdonhang OWNER TO postgres;

--
-- TOC entry 240 (class 1259 OID 17236)
-- Name: chitietdonhang_id_seq; Type: SEQUENCE; Schema: public; Owner: postgres
--

CREATE SEQUENCE public.chitietdonhang_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.chitietdonhang_id_seq OWNER TO postgres;

--
-- TOC entry 5166 (class 0 OID 0)
-- Dependencies: 240
-- Name: chitietdonhang_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: postgres
--

ALTER SEQUENCE public.chitietdonhang_id_seq OWNED BY public.chitietdonhang.id;


--
-- TOC entry 239 (class 1259 OID 17218)
-- Name: donhang; Type: TABLE; Schema: public; Owner: postgres
--

CREATE TABLE public.donhang (
    id bigint NOT NULL,
    nguoidung_id bigint NOT NULL,
    tong_tien bigint DEFAULT 0,
    trang_thai text DEFAULT 'moi'::text,
    created_at timestamp with time zone DEFAULT now()
);


ALTER TABLE public.donhang OWNER TO postgres;

--
-- TOC entry 238 (class 1259 OID 17217)
-- Name: donhang_id_seq; Type: SEQUENCE; Schema: public; Owner: postgres
--

CREATE SEQUENCE public.donhang_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.donhang_id_seq OWNER TO postgres;

--
-- TOC entry 5167 (class 0 OID 0)
-- Dependencies: 238
-- Name: donhang_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: postgres
--

ALTER SEQUENCE public.donhang_id_seq OWNED BY public.donhang.id;


--
-- TOC entry 237 (class 1259 OID 17193)
-- Name: giohang; Type: TABLE; Schema: public; Owner: postgres
--

CREATE TABLE public.giohang (
    id bigint NOT NULL,
    nguoidung_id bigint NOT NULL,
    sanpham_id bigint NOT NULL,
    so_luong integer DEFAULT 1 NOT NULL,
    created_at timestamp with time zone DEFAULT now()
);


ALTER TABLE public.giohang OWNER TO postgres;

--
-- TOC entry 236 (class 1259 OID 17192)
-- Name: giohang_id_seq; Type: SEQUENCE; Schema: public; Owner: postgres
--

CREATE SEQUENCE public.giohang_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.giohang_id_seq OWNER TO postgres;

--
-- TOC entry 5168 (class 0 OID 0)
-- Dependencies: 236
-- Name: giohang_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: postgres
--

ALTER SEQUENCE public.giohang_id_seq OWNED BY public.giohang.id;


--
-- TOC entry 233 (class 1259 OID 17069)
-- Name: giohang_item; Type: TABLE; Schema: public; Owner: postgres
--

CREATE TABLE public.giohang_item (
    id bigint NOT NULL,
    giohang_id bigint NOT NULL,
    sanpham_id bigint NOT NULL,
    so_luong integer NOT NULL,
    don_gia numeric(14,0),
    created_at timestamp with time zone DEFAULT now() NOT NULL,
    CONSTRAINT giohang_item_so_luong_check CHECK ((so_luong > 0))
);


ALTER TABLE public.giohang_item OWNER TO postgres;

--
-- TOC entry 232 (class 1259 OID 17068)
-- Name: giohang_item_id_seq; Type: SEQUENCE; Schema: public; Owner: postgres
--

CREATE SEQUENCE public.giohang_item_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.giohang_item_id_seq OWNER TO postgres;

--
-- TOC entry 5169 (class 0 OID 0)
-- Dependencies: 232
-- Name: giohang_item_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: postgres
--

ALTER SEQUENCE public.giohang_item_id_seq OWNED BY public.giohang_item.id;


--
-- TOC entry 229 (class 1259 OID 16575)
-- Name: goisanpham; Type: TABLE; Schema: public; Owner: postgres
--

CREATE TABLE public.goisanpham (
    magoi integer NOT NULL,
    manguoidung integer,
    danhsachsanphamjson jsonb,
    ngaytao timestamp without time zone DEFAULT CURRENT_TIMESTAMP
);


ALTER TABLE public.goisanpham OWNER TO postgres;

--
-- TOC entry 228 (class 1259 OID 16574)
-- Name: goisanpham_magoi_seq; Type: SEQUENCE; Schema: public; Owner: postgres
--

CREATE SEQUENCE public.goisanpham_magoi_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.goisanpham_magoi_seq OWNER TO postgres;

--
-- TOC entry 5170 (class 0 OID 0)
-- Dependencies: 228
-- Name: goisanpham_magoi_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: postgres
--

ALTER SEQUENCE public.goisanpham_magoi_seq OWNED BY public.goisanpham.magoi;


--
-- TOC entry 221 (class 1259 OID 16440)
-- Name: khachhang; Type: TABLE; Schema: public; Owner: postgres
--

CREATE TABLE public.khachhang (
    makhachhang integer NOT NULL,
    diachigiaohang text,
    diemtl integer DEFAULT 0,
    hangthanhvien character varying(50),
    loai_da character varying(50),
    vandeda text,
    lichsukichung text,
    tinhtrangdacbiet text
);


ALTER TABLE public.khachhang OWNER TO postgres;

--
-- TOC entry 225 (class 1259 OID 16543)
-- Name: lichsuchat; Type: TABLE; Schema: public; Owner: postgres
--

CREATE TABLE public.lichsuchat (
    machat integer NOT NULL,
    manguoidung integer,
    noidung text,
    vaitro character varying(50),
    thoigian timestamp without time zone DEFAULT CURRENT_TIMESTAMP
);


ALTER TABLE public.lichsuchat OWNER TO postgres;

--
-- TOC entry 224 (class 1259 OID 16542)
-- Name: lichsuchat_machat_seq; Type: SEQUENCE; Schema: public; Owner: postgres
--

CREATE SEQUENCE public.lichsuchat_machat_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.lichsuchat_machat_seq OWNER TO postgres;

--
-- TOC entry 5171 (class 0 OID 0)
-- Dependencies: 224
-- Name: lichsuchat_machat_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: postgres
--

ALTER SEQUENCE public.lichsuchat_machat_seq OWNED BY public.lichsuchat.machat;


--
-- TOC entry 227 (class 1259 OID 16559)
-- Name: lichsukhaosatda; Type: TABLE; Schema: public; Owner: postgres
--

CREATE TABLE public.lichsukhaosatda (
    makhaosat integer NOT NULL,
    manguoidung integer,
    ngaykhaosat timestamp without time zone DEFAULT CURRENT_TIMESTAMP,
    ketquajson jsonb
);


ALTER TABLE public.lichsukhaosatda OWNER TO postgres;

--
-- TOC entry 226 (class 1259 OID 16558)
-- Name: lichsukhaosatda_makhaosat_seq; Type: SEQUENCE; Schema: public; Owner: postgres
--

CREATE SEQUENCE public.lichsukhaosatda_makhaosat_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.lichsukhaosatda_makhaosat_seq OWNER TO postgres;

--
-- TOC entry 5172 (class 0 OID 0)
-- Dependencies: 226
-- Name: lichsukhaosatda_makhaosat_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: postgres
--

ALTER SEQUENCE public.lichsukhaosatda_makhaosat_seq OWNED BY public.lichsukhaosatda.makhaosat;


--
-- TOC entry 235 (class 1259 OID 17177)
-- Name: nguoidung; Type: TABLE; Schema: public; Owner: postgres
--

CREATE TABLE public.nguoidung (
    id bigint NOT NULL,
    ho_ten text NOT NULL,
    email text NOT NULL,
    mat_khau_hash text NOT NULL,
    created_at timestamp with time zone DEFAULT now()
);


ALTER TABLE public.nguoidung OWNER TO postgres;

--
-- TOC entry 234 (class 1259 OID 17176)
-- Name: nguoidung_id_seq; Type: SEQUENCE; Schema: public; Owner: postgres
--

CREATE SEQUENCE public.nguoidung_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.nguoidung_id_seq OWNER TO postgres;

--
-- TOC entry 5173 (class 0 OID 0)
-- Dependencies: 234
-- Name: nguoidung_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: postgres
--

ALTER SEQUENCE public.nguoidung_id_seq OWNED BY public.nguoidung.id;


--
-- TOC entry 222 (class 1259 OID 16454)
-- Name: nhanvien; Type: TABLE; Schema: public; Owner: postgres
--

CREATE TABLE public.nhanvien (
    manhanvien integer NOT NULL,
    chucvu character varying(100)
);


ALTER TABLE public.nhanvien OWNER TO postgres;

--
-- TOC entry 223 (class 1259 OID 16465)
-- Name: quantrivien; Type: TABLE; Schema: public; Owner: postgres
--

CREATE TABLE public.quantrivien (
    maquantri integer NOT NULL,
    quyenhan text
);


ALTER TABLE public.quantrivien OWNER TO postgres;

--
-- TOC entry 243 (class 1259 OID 17925)
-- Name: sanpham; Type: TABLE; Schema: public; Owner: postgres
--

CREATE TABLE public.sanpham (
    id bigint NOT NULL,
    ten_san_pham text NOT NULL,
    danh_muc_day_du text,
    loai_san_pham text,
    gia_ban bigint,
    gia_thi_truong bigint,
    tien_tiet_kiem bigint,
    phan_tram_giam numeric(6,2),
    diem_danh_gia numeric(4,2),
    so_luong_danh_gia integer,
    thuong_hieu text,
    xuat_xu_thuong_hieu text,
    noi_san_xuat text,
    dung_tich text,
    loai_da text,
    barcode text,
    ma_san_pham text,
    link_hinh_anh text,
    mo_ta text,
    thanh_phan_chinh text,
    thanh_phan_day_du text,
    hdsd text,
    noi_dung_danh_gia text,
    hoi_dap text,
    url_san_pham text,
    created_at timestamp with time zone DEFAULT now()
);


ALTER TABLE public.sanpham OWNER TO postgres;

--
-- TOC entry 242 (class 1259 OID 17924)
-- Name: sanpham_id_seq; Type: SEQUENCE; Schema: public; Owner: postgres
--

CREATE SEQUENCE public.sanpham_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.sanpham_id_seq OWNER TO postgres;

--
-- TOC entry 5174 (class 0 OID 0)
-- Dependencies: 242
-- Name: sanpham_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: postgres
--

ALTER SEQUENCE public.sanpham_id_seq OWNED BY public.sanpham.id;


--
-- TOC entry 244 (class 1259 OID 17938)
-- Name: sanpham_staging; Type: TABLE; Schema: public; Owner: postgres
--

CREATE TABLE public.sanpham_staging (
    ten_san_pham text,
    danh_muc_day_du text,
    loai_san_pham text,
    gia_ban text,
    gia_thi_truong text,
    tien_tiet_kiem text,
    phan_tram_giam text,
    diem_danh_gia text,
    so_luong_danh_gia text,
    thuong_hieu text,
    xuat_xu_thuong_hieu text,
    noi_san_xuat text,
    dung_tich text,
    loai_da text,
    barcode text,
    ma_san_pham text,
    link_hinh_anh text,
    mo_ta text,
    thanh_phan_chinh text,
    thanh_phan_day_du text,
    hdsd text,
    noi_dung_danh_gia text,
    hoi_dap text,
    url_san_pham text
);


ALTER TABLE public.sanpham_staging OWNER TO postgres;

--
-- TOC entry 220 (class 1259 OID 16414)
-- Name: vaitro; Type: TABLE; Schema: public; Owner: postgres
--

CREATE TABLE public.vaitro (
    mavaitro integer NOT NULL,
    tenvaitro character varying(50),
    mota text
);


ALTER TABLE public.vaitro OWNER TO postgres;

--
-- TOC entry 219 (class 1259 OID 16413)
-- Name: vaitro_mavaitro_seq; Type: SEQUENCE; Schema: public; Owner: postgres
--

CREATE SEQUENCE public.vaitro_mavaitro_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.vaitro_mavaitro_seq OWNER TO postgres;

--
-- TOC entry 5175 (class 0 OID 0)
-- Dependencies: 219
-- Name: vaitro_mavaitro_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: postgres
--

ALTER SEQUENCE public.vaitro_mavaitro_seq OWNED BY public.vaitro.mavaitro;


--
-- TOC entry 4930 (class 2604 OID 16594)
-- Name: aichatbot id; Type: DEFAULT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.aichatbot ALTER COLUMN id SET DEFAULT nextval('public.aichatbot_id_seq'::regclass);


--
-- TOC entry 4942 (class 2604 OID 17240)
-- Name: chitietdonhang id; Type: DEFAULT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.chitietdonhang ALTER COLUMN id SET DEFAULT nextval('public.chitietdonhang_id_seq'::regclass);


--
-- TOC entry 4938 (class 2604 OID 17221)
-- Name: donhang id; Type: DEFAULT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.donhang ALTER COLUMN id SET DEFAULT nextval('public.donhang_id_seq'::regclass);


--
-- TOC entry 4935 (class 2604 OID 17196)
-- Name: giohang id; Type: DEFAULT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.giohang ALTER COLUMN id SET DEFAULT nextval('public.giohang_id_seq'::regclass);


--
-- TOC entry 4931 (class 2604 OID 17072)
-- Name: giohang_item id; Type: DEFAULT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.giohang_item ALTER COLUMN id SET DEFAULT nextval('public.giohang_item_id_seq'::regclass);


--
-- TOC entry 4928 (class 2604 OID 16578)
-- Name: goisanpham magoi; Type: DEFAULT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.goisanpham ALTER COLUMN magoi SET DEFAULT nextval('public.goisanpham_magoi_seq'::regclass);


--
-- TOC entry 4924 (class 2604 OID 16546)
-- Name: lichsuchat machat; Type: DEFAULT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.lichsuchat ALTER COLUMN machat SET DEFAULT nextval('public.lichsuchat_machat_seq'::regclass);


--
-- TOC entry 4926 (class 2604 OID 16562)
-- Name: lichsukhaosatda makhaosat; Type: DEFAULT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.lichsukhaosatda ALTER COLUMN makhaosat SET DEFAULT nextval('public.lichsukhaosatda_makhaosat_seq'::regclass);


--
-- TOC entry 4933 (class 2604 OID 17180)
-- Name: nguoidung id; Type: DEFAULT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.nguoidung ALTER COLUMN id SET DEFAULT nextval('public.nguoidung_id_seq'::regclass);


--
-- TOC entry 4945 (class 2604 OID 17928)
-- Name: sanpham id; Type: DEFAULT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.sanpham ALTER COLUMN id SET DEFAULT nextval('public.sanpham_id_seq'::regclass);


--
-- TOC entry 4922 (class 2604 OID 16417)
-- Name: vaitro mavaitro; Type: DEFAULT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.vaitro ALTER COLUMN mavaitro SET DEFAULT nextval('public.vaitro_mavaitro_seq'::regclass);


--
-- TOC entry 5146 (class 0 OID 16591)
-- Dependencies: 231
-- Data for Name: aichatbot; Type: TABLE DATA; Schema: public; Owner: postgres
--

COPY public.aichatbot (id, modelversion, mota) FROM stdin;
\.


--
-- TOC entry 5156 (class 0 OID 17237)
-- Dependencies: 241
-- Data for Name: chitietdonhang; Type: TABLE DATA; Schema: public; Owner: postgres
--

COPY public.chitietdonhang (id, donhang_id, sanpham_id, so_luong, don_gia) FROM stdin;
\.


--
-- TOC entry 5154 (class 0 OID 17218)
-- Dependencies: 239
-- Data for Name: donhang; Type: TABLE DATA; Schema: public; Owner: postgres
--

COPY public.donhang (id, nguoidung_id, tong_tien, trang_thai, created_at) FROM stdin;
\.


--
-- TOC entry 5152 (class 0 OID 17193)
-- Dependencies: 237
-- Data for Name: giohang; Type: TABLE DATA; Schema: public; Owner: postgres
--

COPY public.giohang (id, nguoidung_id, sanpham_id, so_luong, created_at) FROM stdin;
\.


--
-- TOC entry 5148 (class 0 OID 17069)
-- Dependencies: 233
-- Data for Name: giohang_item; Type: TABLE DATA; Schema: public; Owner: postgres
--

COPY public.giohang_item (id, giohang_id, sanpham_id, so_luong, don_gia, created_at) FROM stdin;
\.


--
-- TOC entry 5144 (class 0 OID 16575)
-- Dependencies: 229
-- Data for Name: goisanpham; Type: TABLE DATA; Schema: public; Owner: postgres
--

COPY public.goisanpham (magoi, manguoidung, danhsachsanphamjson, ngaytao) FROM stdin;
\.


--
-- TOC entry 5136 (class 0 OID 16440)
-- Dependencies: 221
-- Data for Name: khachhang; Type: TABLE DATA; Schema: public; Owner: postgres
--

COPY public.khachhang (makhachhang, diachigiaohang, diemtl, hangthanhvien, loai_da, vandeda, lichsukichung, tinhtrangdacbiet) FROM stdin;
\.


--
-- TOC entry 5140 (class 0 OID 16543)
-- Dependencies: 225
-- Data for Name: lichsuchat; Type: TABLE DATA; Schema: public; Owner: postgres
--

COPY public.lichsuchat (machat, manguoidung, noidung, vaitro, thoigian) FROM stdin;
\.


--
-- TOC entry 5142 (class 0 OID 16559)
-- Dependencies: 227
-- Data for Name: lichsukhaosatda; Type: TABLE DATA; Schema: public; Owner: postgres
--

COPY public.lichsukhaosatda (makhaosat, manguoidung, ngaykhaosat, ketquajson) FROM stdin;
\.


--
-- TOC entry 5150 (class 0 OID 17177)
-- Dependencies: 235
-- Data for Name: nguoidung; Type: TABLE DATA; Schema: public; Owner: postgres
--

COPY public.nguoidung (id, ho_ten, email, mat_khau_hash, created_at) FROM stdin;
\.


--
-- TOC entry 5137 (class 0 OID 16454)
-- Dependencies: 222
-- Data for Name: nhanvien; Type: TABLE DATA; Schema: public; Owner: postgres
--

COPY public.nhanvien (manhanvien, chucvu) FROM stdin;
\.


--
-- TOC entry 5138 (class 0 OID 16465)
-- Dependencies: 223
-- Data for Name: quantrivien; Type: TABLE DATA; Schema: public; Owner: postgres
--

COPY public.quantrivien (maquantri, quyenhan) FROM stdin;
\.


--
-- TOC entry 5158 (class 0 OID 17925)
-- Dependencies: 243
-- Data for Name: sanpham; Type: TABLE DATA; Schema: public; Owner: postgres
--

COPY public.sanpham (id, ten_san_pham, danh_muc_day_du, loai_san_pham, gia_ban, gia_thi_truong, tien_tiet_kiem, phan_tram_giam, diem_danh_gia, so_luong_danh_gia, thuong_hieu, xuat_xu_thuong_hieu, noi_san_xuat, dung_tich, loai_da, barcode, ma_san_pham, link_hinh_anh, mo_ta, thanh_phan_chinh, thanh_phan_day_du, hdsd, noi_dung_danh_gia, hoi_dap, url_san_pham, created_at) FROM stdin;
\.


--
-- TOC entry 5159 (class 0 OID 17938)
-- Dependencies: 244
-- Data for Name: sanpham_staging; Type: TABLE DATA; Schema: public; Owner: postgres
--

COPY public.sanpham_staging (ten_san_pham, danh_muc_day_du, loai_san_pham, gia_ban, gia_thi_truong, tien_tiet_kiem, phan_tram_giam, diem_danh_gia, so_luong_danh_gia, thuong_hieu, xuat_xu_thuong_hieu, noi_san_xuat, dung_tich, loai_da, barcode, ma_san_pham, link_hinh_anh, mo_ta, thanh_phan_chinh, thanh_phan_day_du, hdsd, noi_dung_danh_gia, hoi_dap, url_san_pham) FROM stdin;
\.


--
-- TOC entry 5135 (class 0 OID 16414)
-- Dependencies: 220
-- Data for Name: vaitro; Type: TABLE DATA; Schema: public; Owner: postgres
--

COPY public.vaitro (mavaitro, tenvaitro, mota) FROM stdin;
\.


--
-- TOC entry 5176 (class 0 OID 0)
-- Dependencies: 230
-- Name: aichatbot_id_seq; Type: SEQUENCE SET; Schema: public; Owner: postgres
--

SELECT pg_catalog.setval('public.aichatbot_id_seq', 1, false);


--
-- TOC entry 5177 (class 0 OID 0)
-- Dependencies: 240
-- Name: chitietdonhang_id_seq; Type: SEQUENCE SET; Schema: public; Owner: postgres
--

SELECT pg_catalog.setval('public.chitietdonhang_id_seq', 1, false);


--
-- TOC entry 5178 (class 0 OID 0)
-- Dependencies: 238
-- Name: donhang_id_seq; Type: SEQUENCE SET; Schema: public; Owner: postgres
--

SELECT pg_catalog.setval('public.donhang_id_seq', 1, false);


--
-- TOC entry 5179 (class 0 OID 0)
-- Dependencies: 236
-- Name: giohang_id_seq; Type: SEQUENCE SET; Schema: public; Owner: postgres
--

SELECT pg_catalog.setval('public.giohang_id_seq', 1, false);


--
-- TOC entry 5180 (class 0 OID 0)
-- Dependencies: 232
-- Name: giohang_item_id_seq; Type: SEQUENCE SET; Schema: public; Owner: postgres
--

SELECT pg_catalog.setval('public.giohang_item_id_seq', 1, false);


--
-- TOC entry 5181 (class 0 OID 0)
-- Dependencies: 228
-- Name: goisanpham_magoi_seq; Type: SEQUENCE SET; Schema: public; Owner: postgres
--

SELECT pg_catalog.setval('public.goisanpham_magoi_seq', 1, false);


--
-- TOC entry 5182 (class 0 OID 0)
-- Dependencies: 224
-- Name: lichsuchat_machat_seq; Type: SEQUENCE SET; Schema: public; Owner: postgres
--

SELECT pg_catalog.setval('public.lichsuchat_machat_seq', 1, false);


--
-- TOC entry 5183 (class 0 OID 0)
-- Dependencies: 226
-- Name: lichsukhaosatda_makhaosat_seq; Type: SEQUENCE SET; Schema: public; Owner: postgres
--

SELECT pg_catalog.setval('public.lichsukhaosatda_makhaosat_seq', 1, false);


--
-- TOC entry 5184 (class 0 OID 0)
-- Dependencies: 234
-- Name: nguoidung_id_seq; Type: SEQUENCE SET; Schema: public; Owner: postgres
--

SELECT pg_catalog.setval('public.nguoidung_id_seq', 1, false);


--
-- TOC entry 5185 (class 0 OID 0)
-- Dependencies: 242
-- Name: sanpham_id_seq; Type: SEQUENCE SET; Schema: public; Owner: postgres
--

SELECT pg_catalog.setval('public.sanpham_id_seq', 1, false);


--
-- TOC entry 5186 (class 0 OID 0)
-- Dependencies: 219
-- Name: vaitro_mavaitro_seq; Type: SEQUENCE SET; Schema: public; Owner: postgres
--

SELECT pg_catalog.setval('public.vaitro_mavaitro_seq', 1, false);


--
-- TOC entry 4963 (class 2606 OID 16599)
-- Name: aichatbot aichatbot_pkey; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.aichatbot
    ADD CONSTRAINT aichatbot_pkey PRIMARY KEY (id);


--
-- TOC entry 4979 (class 2606 OID 17248)
-- Name: chitietdonhang chitietdonhang_pkey; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.chitietdonhang
    ADD CONSTRAINT chitietdonhang_pkey PRIMARY KEY (id);


--
-- TOC entry 4977 (class 2606 OID 17230)
-- Name: donhang donhang_pkey; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.donhang
    ADD CONSTRAINT donhang_pkey PRIMARY KEY (id);


--
-- TOC entry 4965 (class 2606 OID 17083)
-- Name: giohang_item giohang_item_giohang_id_sanpham_id_key; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.giohang_item
    ADD CONSTRAINT giohang_item_giohang_id_sanpham_id_key UNIQUE (giohang_id, sanpham_id);


--
-- TOC entry 4967 (class 2606 OID 17081)
-- Name: giohang_item giohang_item_pkey; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.giohang_item
    ADD CONSTRAINT giohang_item_pkey PRIMARY KEY (id);


--
-- TOC entry 4973 (class 2606 OID 17206)
-- Name: giohang giohang_nguoidung_id_sanpham_id_key; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.giohang
    ADD CONSTRAINT giohang_nguoidung_id_sanpham_id_key UNIQUE (nguoidung_id, sanpham_id);


--
-- TOC entry 4975 (class 2606 OID 17204)
-- Name: giohang giohang_pkey; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.giohang
    ADD CONSTRAINT giohang_pkey PRIMARY KEY (id);


--
-- TOC entry 4961 (class 2606 OID 16584)
-- Name: goisanpham goisanpham_pkey; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.goisanpham
    ADD CONSTRAINT goisanpham_pkey PRIMARY KEY (magoi);


--
-- TOC entry 4951 (class 2606 OID 16448)
-- Name: khachhang khachhang_pkey; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.khachhang
    ADD CONSTRAINT khachhang_pkey PRIMARY KEY (makhachhang);


--
-- TOC entry 4957 (class 2606 OID 16552)
-- Name: lichsuchat lichsuchat_pkey; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.lichsuchat
    ADD CONSTRAINT lichsuchat_pkey PRIMARY KEY (machat);


--
-- TOC entry 4959 (class 2606 OID 16568)
-- Name: lichsukhaosatda lichsukhaosatda_pkey; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.lichsukhaosatda
    ADD CONSTRAINT lichsukhaosatda_pkey PRIMARY KEY (makhaosat);


--
-- TOC entry 4969 (class 2606 OID 17191)
-- Name: nguoidung nguoidung_email_key; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.nguoidung
    ADD CONSTRAINT nguoidung_email_key UNIQUE (email);


--
-- TOC entry 4971 (class 2606 OID 17189)
-- Name: nguoidung nguoidung_pkey; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.nguoidung
    ADD CONSTRAINT nguoidung_pkey PRIMARY KEY (id);


--
-- TOC entry 4953 (class 2606 OID 16459)
-- Name: nhanvien nhanvien_pkey; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.nhanvien
    ADD CONSTRAINT nhanvien_pkey PRIMARY KEY (manhanvien);


--
-- TOC entry 4955 (class 2606 OID 16472)
-- Name: quantrivien quantrivien_pkey; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.quantrivien
    ADD CONSTRAINT quantrivien_pkey PRIMARY KEY (maquantri);


--
-- TOC entry 4981 (class 2606 OID 17935)
-- Name: sanpham sanpham_pkey; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.sanpham
    ADD CONSTRAINT sanpham_pkey PRIMARY KEY (id);


--
-- TOC entry 4983 (class 2606 OID 17937)
-- Name: sanpham sanpham_url_san_pham_key; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.sanpham
    ADD CONSTRAINT sanpham_url_san_pham_key UNIQUE (url_san_pham);


--
-- TOC entry 4949 (class 2606 OID 16422)
-- Name: vaitro vaitro_pkey; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.vaitro
    ADD CONSTRAINT vaitro_pkey PRIMARY KEY (mavaitro);


--
-- TOC entry 4986 (class 2606 OID 17249)
-- Name: chitietdonhang chitietdonhang_donhang_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.chitietdonhang
    ADD CONSTRAINT chitietdonhang_donhang_id_fkey FOREIGN KEY (donhang_id) REFERENCES public.donhang(id) ON DELETE CASCADE;


--
-- TOC entry 4985 (class 2606 OID 17231)
-- Name: donhang donhang_nguoidung_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.donhang
    ADD CONSTRAINT donhang_nguoidung_id_fkey FOREIGN KEY (nguoidung_id) REFERENCES public.nguoidung(id) ON DELETE CASCADE;


--
-- TOC entry 4984 (class 2606 OID 17207)
-- Name: giohang giohang_nguoidung_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.giohang
    ADD CONSTRAINT giohang_nguoidung_id_fkey FOREIGN KEY (nguoidung_id) REFERENCES public.nguoidung(id) ON DELETE CASCADE;


-- Completed on 2026-01-25 14:15:39

--
-- PostgreSQL database dump complete
--

\unrestrict 3aqwpHYBf7oGCk15oUbwn9vyGpEMDQV1S8cYfI3I7ozj3evTT4tb3HonldQKtVd

