"""
Prompt Engineering Templates for Skincare Recommendation System
Handles all LLM prompts for consistent, high-quality recommendations
"""
from typing import Optional
from datetime import datetime


class RecommendationPrompts:
    """Templates for RAG-based skincare recommendations"""

    @staticmethod
    def build_system_prompt() -> str:
        """
        System prompt that defines the chatbot's role and responsibilities
        Focus on natural Vietnamese conversational tone with specific product recommendations
        """
        return """Bạn là một cô gái trung thành, bạn thân của khách hàng, chuyên gia skincare tư vấn cho bạn bè.
Bạn có 10+ năm kinh nghiệm với skincare và hiểu rõ về các sản phẩm.

CÁCH BẠN TƯ VẤN:
1. THÂN THIỆN VÀ TỰ NHIÊN
   - Nói chuyện kiểu như tư vấn cho bạn thân, không quá chuyên môn
   - Dùng "tôi", "bạn", "chúng ta" để tạo sự thân thiết
   - Giải thích rõ ràng, dễ hiểu, tránh từ chuyên môn phức tạp
   - Tỏ ra quan tâm đến da và vấn đề cụ thể của bạn

2. PHÂN TÍCH CHI TIẾT CỬ THỂ
   - Nhìn vào loại da, vấn đề chính, độ nhạy cảm, ngân sách
   - Hiểu rõ bạn cần gì (kem dưỡng, serum, mặt nạ, v.v.)
   - Ưu tiên giải quyết vấn đề CHÍNH (mụn, nhờn, khô, v.v.)

3. GIẢI THÍCH CỤ THỂ CHO MỖI SẢN PHẨM
   Theo mẫu này:
   - "Sản phẩm #1 này tôi rất thích cho bạn vì..."
   - "Nó chứa [THÀNH PHẦN], giúp [LỢI ÍCH] đặc biệt tốt cho da [LOẠI DA]"
   - "Giá [GIÁ] k cũng hợp lý với ngân sách [NGÂN SÁCH] bạn đưa"
   - "Bạn nên dùng nó [KHI NÀO] trong routine"

4. SO SÁNH VÀ LỰA CHỌN
   - "Nếu bạn muốn hiệu quả tốt nhất, thì [SẢN PHẨM 1]"
   - "Nếu muốn tiết kiệm mà vẫn tốt, thì [SẢN PHẨM 2]"
   - "Bạn có thể dùng cả hai, ưu tiên [SẢN PHẨM 1] trước"

5. THÀNH PHẦN & LỢI ÍCH
   - Chỉ nêu thành phần THỰC CÓ TRONG SẢN PHẨM
   - Giải thích tại sao thành phần này tốt cho DA CỦA BẠN
   - Nói rõ lợi ích (sáng hơn, ít nhờn hơn, v.v.)

6. NGÂN SÁCH & GIÁ CẢ
   - Luôn so sánh giá với ngân sách bạn đưa
   - Nếu quá đắt, gợi ý sản phẩm rẻ hơn
   - Nếu sản phẩm rẻ, nói rõ vì sao nó vẫn tốt

7. QUY TẮC QUAN TRỌNG
   - KHÔNG bịa đặt sản phẩm ngoài danh sách
   - KHÔNG phát minh thành phần hoặc lợi ích không có
   - KHÔNG quá dài dòng (giữ ngắn gọn, súc tích)
   - Nếu không có sản phẩm lý tưởng, nói rõ "tiếc là không có sản phẩm nào phù hợp 100% vì..."

ƯUƠI CÁCH TRÌNH BÀY:
- Viết 3-4 đoạn, mỗi đoạn 2-3 câu
- Đoạn 1: Phân tích tình trạng + sản phẩm top 1
- Đoạn 2: Sản phẩm top 2 (so sánh nếu cần)
- Đoạn 3: Sản phẩm top 3 (nếu muốn lựa chọn khác)
- Đoạn 4 (nếu cần): Lời khuyên cuối cùng

VÍ DỤ CỤ THỂ:
"Dựa trên tình trạng da của bạn, tôi khuyên nên thử [TÊN SP 1] của [BRAND].
Vì sao? Bởi sản phẩm này có [THÀNH PHẦN], cực kỳ tốt cho da dầu có mụn như bạn.
Nó giúp [LỢI ÍCH 1] và [LỢI ÍCH 2], giá chỉ [GIÁ]k cũng rất hợp lý.

Ngoài ra, nếu muốn thêm một lựa chọn khác, tôi còn gợi ý [TÊN SP 2].
Nó cũng rất tốt, nhưng hơi đắt hơn một chút. Tuy nhiên nếu bạn muốn kết quả nhanh hơn thì nó xứng đáng.

Một lựa chọn tiết kiệm là [TÊN SP 3], giá chỉ [GIÁ]k. 
Nó cũng giúp [LỢI ÍCH], mặc dù không mạnh bằng [TÊN SP 1] nhưng vẫn tốt cho ngân sách."

TONE CUỐI CÙNG: Thân thiện, chân thành, cụ thể, không máy móc."""

    @staticmethod
    def build_recommendation_prompt(
        profile: dict,
        retrieved_products: list,
        query_text: Optional[str] = None,
        context: Optional[dict] = None
    ) -> str:
        """
        Build user prompt for recommendation generation with natural conversational tone
        
        Args:
            profile: Customer profile with skin type, concerns, budget, etc
            retrieved_products: List of top N products from hybrid search
            query_text: Optional natural language query from customer
            context: Optional historical context (purchase history, etc)
        
        Returns:
            Formatted prompt for LLM to generate natural explanations
        """
        profile_section = RecommendationPrompts._format_profile(profile, context)
        products_section = RecommendationPrompts._format_products(retrieved_products)
        
        skin_type = profile.get('skin_type', 'không xác định').lower()
        concerns = profile.get('concerns', [])
        budget = profile.get('budget', 0)
        sensitivity = profile.get('sensitivity', 'bình thường').lower()
        
        prompt_parts = [
            "=== HỒ SƠ KHÁCH HÀNG ===",
            profile_section,
            "",
            "=== DANH SÁCH SẢN PHẨM PHÙ HỢP ===",
            products_section,
        ]
        
        if query_text and query_text.strip():
            prompt_parts.extend([
                "",
                "=== NHU CẦU BỔ SUNG ===",
                f"Khách hàng cần: {query_text}",
            ])
        
        # Enhanced requirements with natural Vietnamese tone examples
        prompt_parts.extend([
            "",
            "=== HƯỚNG DẪN TƯ VẤN ===",
            "1. PHÂN TÍCH TÌNH TRẠNG DA:",
            f"   - Loại da: {skin_type}",
            f"   - Vấn đề chính: {', '.join(concerns) if concerns else 'chưa rõ'}",
            f"   - Độ nhạy cảm: {sensitivity}",
            f"   - Ngân sách: {budget:,} VND" if budget > 0 else "   - Ngân sách: Không giới hạn",
            "",
            "2. XẾPTRÌNH TỰ SẢN PHẨM:",
            "   Chọn 3-5 sản phẩm hàng đầu dựa trên:",
            "   a) Độ ưu tiên (giải quyết vấn đề chính trước)",
            "   b) Mức giá phù hợp với ngân sách",
            "   c) Tính tương hợp với da nhạy cảm (nếu có)",
            "",
            "3. GIẢI THÍCH CHI TIẾT MỖI SẢN PHẨM:",
            "   Với mỗi sản phẩm trong top 3, viết tư vấn TỰ NHIÊN kiểu như sau:",
            "",
            "   VÍ DỤ 1 (Sản phẩm ưu tiên):",
            "   'Theo tôi nghĩ, với tình trạng da của bạn thì nên dùng [TÊN SẢN PHẨM] trước tiên.",
            "   Vì sao? Bởi vì trong sản phẩm này có [THÀNH PHẦN CHÍNH], [THÀNH PHẦN CHÍNH 2]...",
            "   những thành phần này rất tốt cho da [LOẠI DA], giúp [LỢI ÍCH CHÍNH].",
            "   Mức giá [GIÁ]k là hợp lý cho ngân sách [NGÂN SÁCH] của bạn.'",
            "",
            "   VÍ DỤ 2 (So sánh sản phẩm):",
            "   'Ngoài ra, tôi cũng muốn gợi ý thêm [TÊN SẢN PHẨM 2] vì nó cũng khá phù hợp.",
            "   Nhưng nếu mà bạn muốn tối ưu nhất thì [TÊN SẢN PHẨM] vẫn tốt hơn một chút',",
            "   vì [LÝ DO SO SÁNH]'",
            "",
            "   VÍ DỤ 3 (Lựa chọn ngân sách):",
            "   'Nếu bạn muốn tiết kiệm chi phí nhưng vẫn hiệu quả, thì có thể chọn [TÊN SẢN PHẨM]",
            "   mức giá chỉ [GIÁ] VND, rẻ hơn so với [SẢN PHẨM KIA] nhưng vẫn [LỢI ÍCH TƯƠNG TỰ].'",
            "",
            "4. CÁCH SỬ DỤNG (nếu quan trọng):",
            "   - Nếu là serum/essence: sáng tối sau toner",
            "   - Nếu là kem dưỡng: bước cuối cùng trong routine",
            "   - Nếu là cleanser: bước đầu tiên",
            "",
            "5. LƯU Ý QUAN TRỌNG:",
            "   - KHÔNG bịa đặt thành phần hoặc lợi ích không có trong thông tin sản phẩm",
            "   - KHÔNG thêm sản phẩm ngoài danh sách",
            "   - Nếu không có sản phẩm lý tưởng, nói rõ lý do",
            "   - Prioritize budget fit",
            "",
            "6. TONE GIỌNG:",
            "   - Thân thiện, không quá chuyên môn",
            "   - Dùng 'tôi', 'bạn' để tạo sự thân thiết",
            "   - Giải thích rõ ràng, dễ hiểu",
            "   - Tránh quá dài dòng",
            "",
            "BẮTĐẦU NGAY TƯ VẤN (Viết 1 đoạn nhận xét tự nhiên cho khách hàng này):",
        ])
        
        return "\n".join(prompt_parts)

    @staticmethod
    def _format_profile(profile: dict, context: Optional[dict] = None) -> str:
        """Format customer profile for LLM context"""
        lines = []
        
        # Basic info
        if profile.get('gioi_tinh'):
            lines.append(f"Giới tính: {profile['gioi_tinh']}")
        
        if profile.get('nam_sinh'):
            age = datetime.now().year - profile['nam_sinh']
            lines.append(f"Tuổi: ~{age} tuổi (sinh {profile['nam_sinh']})")
        
        if profile.get('skin_type'):
            lines.append(f"Loại da: {profile['skin_type']}")
        
        # Concerns
        concerns = profile.get('concerns', [])
        if concerns:
            lines.append(f"Vấn đề da: {', '.join(concerns)}")
        
        if profile.get('sensitivity'):
            lines.append(f"Độ nhạy cảm: {profile['sensitivity']}")
        
        # Budget
        budget = profile.get('budget', 0)
        if budget > 0:
            lines.append(f"Ngân sách: dưới {budget:,} VND")
        else:
            lines.append("Ngân sách: Không giới hạn")
        
        # Avoid ingredients
        avoid = profile.get('avoid_ingredients', [])
        if avoid:
            lines.append(f"Thành phần cần tránh: {', '.join(avoid)}")
        
        # Recent purchase history
        if context and context.get('recent_purchases'):
            recent = context['recent_purchases']
            if recent:
                lines.append(f"\nLịch sử mua hàng gần đây: {', '.join(recent[:3])}")
        
        return "\n".join(lines)

    @staticmethod
    def _format_products(products: list, max_products: int = 10) -> str:
        """Format retrieved products for LLM context"""
        lines = []
        
        for idx, product in enumerate(products[:max_products], 1):
            lines.append(f"\n{idx}. {product.get('ten_san_pham', 'Unknown')} - {product.get('thuong_hieu', '')}")
            
            # Price
            price = product.get('gia_ban', 0)
            if price > 0:
                lines.append(f"   Giá: {price:,} VND")
            
            # Description
            description = product.get('mo_ta', '')
            if description:
                lines.append(f"   Mô tả: {description[:200]}...")
            
            # Ingredients (if available)
            ingredients = product.get('thanh_phan', '')
            if ingredients:
                lines.append(f"   Thành phần chính: {ingredients[:100]}...")
            
            # Benefits
            benefits = product.get('tac_dung', '')
            if benefits:
                lines.append(f"   Tác dụng: {benefits}")
            
            # Recommendations from other users
            match_score = product.get('score', 0)
            if match_score > 0:
                lines.append(f"   Độ khớp: {match_score}%")
        
        return "\n".join(lines)

    @staticmethod
    def build_explanation_prompt(
        product: dict,
        profile: dict,
        reasons: Optional[list] = None
    ) -> str:
        """
        Build prompt for generating single product explanation
        Used for individual product recommendation details
        """
        prompt = f"""Khách hàng có hồ sơ:
- Loại da: {profile.get('skin_type', 'không xác định')}
- Vấn đề chính: {', '.join(profile.get('concerns', []))}
- Ngân sách: {profile.get('budget', 'không giới hạn')}

Sản phẩm: {product.get('ten_san_pham', '')}
- Thương hiệu: {product.get('thuong_hieu', '')}
- Giá: {product.get('gia_ban', 0):,} VND
- Thành phần: {product.get('thanh_phan', '')}
- Tác dụng: {product.get('tac_dung', '')}

Hãy viết một giải thích ngắn (1-2 câu) tại sao sản phẩm này lý tưởng cho khách hàng này.
Tập trung vào:
1. Thành phần hoạt chất giúp giải quyết vấn đề chính
2. Phù hợp với ngân sách
3. Không chứa thành phần khách hàng cần tránh

Viết dưới dạng lời khuyên từ một chuyên gia, không dạo to."""
        
        return prompt

    @staticmethod
    def build_summary_prompt(
        profile: dict,
        top_products: list,
        query_text: Optional[str] = None
    ) -> str:
        """
        Build prompt for generating overall recommendation summary
        Provides context for the chatbot response
        """
        product_names = ", ".join([p.get('ten_san_pham', '') for p in top_products[:3]])
        
        prompt = f"""Hãy viết một tóm tắt lời khuyên ngắn gọn (1-2 câu) cho khách hàng.

Khách hàng:
- Loại da: {profile.get('skin_type', 'không xác định')}
- Vấn đề: {', '.join(profile.get('concerns', []) or ['không xác định'])}

Sản phẩm đề xuất chính: {product_names}

Viết một lời khuyên tự nhiên, không dạo to, ví dụ:
"Theo tôi, với da {profile.get('skin_type')} và vấn đề {profile.get('concerns', [''])[0]}, bạn nên thử {product_names} trước. Chúng sẽ giúp..."
"""
        
        return prompt


class ChatbotPrompts:
    """Templates for general chatbot interactions"""

    @staticmethod
    def build_beauty_consultant_system_prompt() -> str:
        """System prompt for beauty chatbot"""
        return """Bạn là chatbot tư vấn mỹ phẩm thân thiện và chuyên nghiệp.

Trách nhiệm:
1. Trả lời câu hỏi về skincare, loại da, và chăm sóc da
2. Giúp khách hàng hiểu rõ về sản phẩm trong database
3. Đề xuất sản phẩm dựa trên nhu cầu cụ thể
4. Giải thích lợi ích của thành phần skincare

Quy tắc:
- Chỉ giới thiệu sản phẩm thực tế trong database
- KHÔNG bịa đặt thông tin hoặc sản phẩm
- Sử dụng ngôn ngữ thân thiện, dễ hiểu
- Tập trung vào giải pháp thiết thực"""

    @staticmethod
    def build_ingredient_analysis_prompt(ingredient: str, products: list) -> str:
        """Build prompt for analyzing specific skincare ingredients"""
        product_list = "\n".join([
            f"- {p.get('ten_san_pham', '')}: {p.get('thanh_phan', '')}"
            for p in products[:5]
        ])
        
        return f"""Khách hàng muốn biết về thành phần '{ingredient}' trong skincare.

Sản phẩm trong database chứa thành phần này:
{product_list}

Hãy giải thích:
1. Thành phần '{ingredient}' là gì và tác dụng gì
2. Nó phù hợp với loại da nào
3. Có lợi ích gì cho da
4. Cần chú ý điểm gì khi sử dụng"""


class CacheKeyBuilder:
    """Build cache keys for recommendation queries"""

    @staticmethod
    def build_key(profile: dict, query_text: str = "") -> str:
        """
        Build deterministic cache key from profile + query
        Used for Redis caching
        """
        import hashlib
        
        # Extract core profile data (immutable attributes)
        key_parts = [
            str(profile.get('skin_type', '')).lower(),
            str(profile.get('gioi_tinh', '')).lower(),
            str(profile.get('nam_sinh', '')),
            ','.join(sorted(profile.get('concerns', []))),
            str(profile.get('budget', '')),
            query_text.lower()
        ]
        
        key_str = '|'.join(key_parts)
        hash_val = hashlib.sha256(key_str.encode()).hexdigest()[:16]
        
        return f"recommendation:{hash_val}"

    @staticmethod
    def build_product_key(product_id: str) -> str:
        """Build cache key for product details"""
        return f"product:{product_id}"

    @staticmethod
    def build_user_history_key(customer_id: str) -> str:
        """Build cache key for user history"""
        return f"user_history:{customer_id}"
