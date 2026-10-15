"""
Apriori Implementation from First Principles for SkinSyntaxVN Research.

This module implements the classical Apriori algorithm (Agrawal & Srikant, 1994)
from first principles for educational transparency, thesis defense, and validation.
No black-box recommendation or ML libraries are used in this core implementation.
"""

from itertools import combinations
from typing import List, Set, Dict, Tuple, Any


def get_transactions_from_matrix(df) -> List[Set[str]]:
    """Convert binary incidence DataFrame (rows=baskets, cols=items) into list of item sets."""
    transactions = []
    for _, row in df.iterrows():
        basket = set(row.index[row == 1])
        transactions.append(basket)
    return transactions


def count_support(itemset: Set[str], transactions: List[Set[str]]) -> int:
    """Count the number of transactions that contain the itemset."""
    return sum(1 for tx in transactions if itemset.issubset(tx))


def apriori_first_principles(
    transactions: List[Set[str]],
    min_support: float = 0.10,
    min_confidence: float = 0.20
) -> Dict[str, Any]:
    """
    Run Apriori from first principles.
    
    Args:
        transactions: List of sets of items for each transaction.
        min_support: Threshold between 0 and 1.
        min_confidence: Threshold between 0 and 1.
        
    Returns:
        Dict containing:
            - iterations: Details of Ck and Lk for each k.
            - frequent_itemsets: Dict mapping frozenset(itemset) to support and count.
            - rules: List of generated association rules.
            - N_transactions: Total transaction count.
    """
    N = len(transactions)
    # Use small epsilon tolerance (1e-7) so rounded inputs like 0.0667 for 2/30 don't miss count 2 due to 2.001
    min_support_count = (min_support * N) - 1e-7
    
    iterations = []
    frequent_itemsets = {}  # frozenset -> {'count': int, 'support': float, 'k': int}
    
    # --- Step 1: Generate C1 and L1 ---
    # Find all unique single items
    all_items = sorted(list(set.union(*transactions))) if transactions else []
    C1 = [frozenset([item]) for item in all_items]
    
    L1 = {}
    for c in C1:
        cnt = count_support(c, transactions)
        if cnt >= min_support_count:
            supp = cnt / N
            L1[c] = {'count': cnt, 'support': supp, 'k': 1}
            frequent_itemsets[c] = L1[c]
            
    iterations.append({
        'k': 1,
        'C_count': len(C1),
        'L_count': len(L1),
        'candidates': [sorted(list(c)) for c in C1],
        'frequent_itemsets': [{
            'itemset': sorted(list(itemset)),
            'count': meta['count'],
            'support': round(meta['support'], 4)
        } for itemset, meta in L1.items()]
    })
    
    current_L = L1
    k = 2
    
    while current_L:
        prev_frequent_set = set(current_L.keys())
        prev_frequent_list = sorted(list(prev_frequent_set), key=lambda x: sorted(list(x)))
        
        # --- Candidate Generation (Apriori Join Step) ---
        Ck_candidates = set()
        for i in range(len(prev_frequent_list)):
            for j in range(i + 1, len(prev_frequent_list)):
                union_set = prev_frequent_list[i] | prev_frequent_list[j]
                if len(union_set) == k:
                    Ck_candidates.add(union_set)
                    
        # --- Apriori Pruning Step ---
        # If any (k-1)-subset of candidate is not frequent, prune that candidate.
        pruned_Ck = []
        for candidate in sorted(list(Ck_candidates), key=lambda x: sorted(list(x))):
            subsets = [frozenset(s) for s in combinations(candidate, k - 1)]
            is_valid = all(s in prev_frequent_set for s in subsets)
            if is_valid:
                pruned_Ck.append(candidate)
                
        # --- Candidate Counting & Filtering for Lk ---
        Lk = {}
        for c in pruned_Ck:
            cnt = count_support(c, transactions)
            if cnt >= min_support_count:
                supp = cnt / N
                Lk[c] = {'count': cnt, 'support': supp, 'k': k}
                frequent_itemsets[c] = Lk[c]
                
        iterations.append({
            'k': k,
            'C_count': len(pruned_Ck),
            'L_count': len(Lk),
            'candidates': [sorted(list(c)) for c in pruned_Ck],
            'frequent_itemsets': [{
                'itemset': sorted(list(itemset)),
                'count': meta['count'],
                'support': round(meta['support'], 4)
            } for itemset, meta in Lk.items()]
        })
        
        current_L = Lk
        k += 1
        
    # --- Step 2: Generate Association Rules ---
    rules = []
    for itemset, meta in frequent_itemsets.items():
        if len(itemset) < 2:
            continue
            
        itemset_count = meta['count']
        itemset_supp = meta['support']
        
        # Generate non-empty proper subsets for antecedent
        items_list = list(itemset)
        for r in range(1, len(items_list)):
            for ant_combo in combinations(items_list, r):
                ant = frozenset(ant_combo)
                con = itemset - ant
                
                ant_meta = frequent_itemsets.get(ant)
                con_meta = frequent_itemsets.get(con)
                
                if not ant_meta or not con_meta:
                    continue
                    
                ant_count = ant_meta['count']
                ant_supp = ant_meta['support']
                con_count = con_meta['count']
                con_supp = con_meta['support']
                
                confidence = itemset_count / ant_count
                
                if confidence >= min_confidence:
                    lift = confidence / con_supp if con_supp > 0 else 0.0
                    
                    rules.append({
                        'antecedent': sorted(list(ant)),
                        'consequent': sorted(list(con)),
                        'support_count': itemset_count,
                        'support': round(itemset_supp, 4),
                        'antecedent_support': round(ant_supp, 4),
                        'consequent_support': round(con_supp, 4),
                        'confidence': round(confidence, 4),
                        'lift': round(lift, 4)
                    })
                    
    # Sort rules deterministically by confidence desc, lift desc, support desc
    rules.sort(key=lambda r: (-r['confidence'], -r['lift'], -r['support'], r['antecedent'], r['consequent']))
    
    return {
        'N_transactions': N,
        'min_support': min_support,
        'min_confidence': min_confidence,
        'min_support_count': min_support_count,
        'iterations': iterations,
        'frequent_itemsets': frequent_itemsets,
        'rules': rules
    }
