# Database Notes

## MongoDB Collections Used By Recommendation

- `san_pham`: product source of truth.
- `khach_hang`: customer profile and survey fields.
- `loai_da`: skin type lookup.
- `hoa_don`: order headers.
- `chi_tiet_hoa_don`: order items.
- `gio_hang`: active cart items when available.
- `lich_su_chat`: recent chat signals when available.
- `thuong_hieu`: brand lookup.
- `danh_muc`: category lookup.

## Recommendation Index

- LlamaIndex product index is stored under `database/recommendation_index`.
- The chatbot ChromaDB at `database/chroma_db` is not modified by recommendation indexing.

