"""
FP-Growth Implementation from First Principles for SkinSyntaxVN Research.

This module implements Han et al. (2000) Mining Frequent Patterns without Candidate Generation (FP-Growth)
from first principles for educational transparency, thesis defense, and validation.
No black-box recommendation or ML libraries are used in this core implementation.
"""

from itertools import combinations
from typing import List, Set, Dict, Tuple, Any, Optional


class FPNode:
    """A node in the FP-tree."""
    _node_counter = 0

    def __init__(self, item: Optional[str], count: int, parent: Optional['FPNode']):
        FPNode._node_counter += 1
        self.node_id = FPNode._node_counter
        self.item = item
        self.count = count
        self.parent = parent
        self.children: Dict[str, 'FPNode'] = {}
        self.node_link: Optional['FPNode'] = None

    def increment(self, count: int = 1):
        self.count += count

    def is_root(self) -> bool:
        return self.item is None

    def to_dict(self) -> Dict[str, Any]:
        """Convert tree rooted at this node to serializable dictionary."""
        return {
            'node_id': self.node_id,
            'item': self.item if self.item else 'ROOT',
            'count': self.count,
            'parent_item': self.parent.item if self.parent and self.parent.item else ('ROOT' if self.parent else None),
            'children': [child.to_dict() for child in self.children.values()]
        }


class FPTree:
    """FP-Tree structure containing root and header table."""

    def __init__(self):
        self.root = FPNode(item=None, count=0, parent=None)
        # header_table: item -> [total_support_count, head_node]
        self.header_table: Dict[str, List[Any]] = {}

    def insert_transaction(self, transaction: List[str], count: int = 1):
        """Insert a sorted list of frequent items into the tree."""
        current_node = self.root
        current_node.count += count
        for item in transaction:
            if item in current_node.children:
                current_node.children[item].increment(count)
            else:
                new_node = FPNode(item=item, count=count, parent=current_node)
                current_node.children[item] = new_node
                
                # Update header table node-link chain
                if item in self.header_table:
                    if self.header_table[item][1] is None:
                        self.header_table[item][1] = new_node
                    else:
                        current_link = self.header_table[item][1]
                        while current_link.node_link is not None:
                            current_link = current_link.node_link
                        current_link.node_link = new_node
            current_node = current_node.children[item]

    def count_nodes(self) -> int:
        """Count total nodes in the FP-tree excluding root."""
        def _count(node: FPNode) -> int:
            cnt = 1 if not node.is_root() else 0
            for child in node.children.values():
                cnt += _count(child)
            return cnt
        return _count(self.root)


def build_fp_tree(
    transactions: List[List[str]],
    min_support_count: float,
    item_frequencies: Optional[Dict[str, int]] = None
) -> Tuple[Optional[FPTree], Dict[str, int]]:
    """
    Build FP-tree from transactions.
    
    Args:
        transactions: List of item lists (can have counts if weighted).
        min_support_count: Absolute minimum frequency count.
        item_frequencies: Optional precomputed frequencies.
    """
    # 1. Count frequencies if not provided
    if item_frequencies is None:
        item_frequencies = {}
        for tx in transactions:
            for item in tx:
                item_frequencies[item] = item_frequencies.get(item, 0) + 1

    # 2. Filter items below min_support
    frequent_items = {
        item: cnt for item, cnt in item_frequencies.items() if cnt >= min_support_count
    }
    if not frequent_items:
        return None, {}

    # Initialize FPTree and header table
    fp_tree = FPTree()
    for item, cnt in frequent_items.items():
        fp_tree.header_table[item] = [cnt, None]

    # Deterministic sort order: count descending, then item ascending
    def sort_key(item):
        return (-frequent_items[item], item)

    # 3. Insert each transaction (filtered and sorted)
    for tx in transactions:
        filtered_tx = [item for item in tx if item in frequent_items]
        if filtered_tx:
            filtered_tx.sort(key=sort_key)
            fp_tree.insert_transaction(filtered_tx, count=1)

    return fp_tree, frequent_items


def find_prefix_paths(base_node: Optional[FPNode]) -> Dict[Tuple[str, ...], int]:
    """Traverse the node-link chain and extract prefix paths to root with their path counts."""
    conditional_patterns = {}
    current = base_node
    while current is not None:
        prefix_path = []
        parent = current.parent
        while parent is not None and not parent.is_root():
            prefix_path.append(parent.item)
            parent = parent.parent
        if prefix_path:
            # Reverse so path is from root downwards
            conditional_patterns[tuple(reversed(prefix_path))] = current.count
        current = current.node_link
    return conditional_patterns


def mine_fp_tree(
    fp_tree: Optional[FPTree],
    prefix: Set[str],
    min_support_count: float,
    frequent_itemsets: Dict[frozenset, Dict[str, Any]],
    diagnostics: Dict[str, int],
    N_total: int,
    detailed_examples: Optional[Dict[str, Any]] = None,
    track_items: Optional[Set[str]] = None
):
    """
    Recursively mine FP-tree by constructing conditional pattern bases and conditional FP-trees.
    """
    diagnostics['recursive_mining_calls'] += 1
    if fp_tree is None:
        return

    # Process items in header table sorted by ascending frequency (bottom-up)
    sorted_items = sorted(fp_tree.header_table.items(), key=lambda x: (x[1][0], x[0]))

    for item, (count, head_node) in sorted_items:
        new_prefix = prefix.copy()
        new_prefix.add(item)
        new_itemset = frozenset(new_prefix)

        # Record frequent itemset
        frequent_itemsets[new_itemset] = {
            'count': count,
            'support': count / N_total,
            'k': len(new_itemset)
        }

        # Find prefix paths for this item (conditional pattern base)
        conditional_patterns = find_prefix_paths(head_node)
        diagnostics['conditional_pattern_bases_count'] += 1

        # Track examples for thesis visualization if requested
        if detailed_examples is not None and track_items and item in track_items and len(prefix) == 0:
            cond_item_counts = {}
            for path, path_cnt in conditional_patterns.items():
                for p_item in path:
                    cond_item_counts[p_item] = cond_item_counts.get(p_item, 0) + path_cnt
            
            detailed_examples[item] = {
                'item': item,
                'header_table_frequency': count,
                'conditional_pattern_base': [
                    {'prefix_path': list(path), 'path_count': path_cnt}
                    for path, path_cnt in conditional_patterns.items()
                ],
                'conditional_item_counts': cond_item_counts,
                'retained_conditional_items': {
                    it: c for it, c in cond_item_counts.items() if c >= min_support_count
                }
            }

        # If conditional pattern base is empty, continue
        if not conditional_patterns:
            continue

        # Count frequencies within conditional pattern base
        cond_item_freqs = {}
        for path, path_cnt in conditional_patterns.items():
            for p_item in path:
                cond_item_freqs[p_item] = cond_item_freqs.get(p_item, 0) + path_cnt

        # Filter items meeting min_support_count
        cond_frequent = {it: cnt for it, cnt in cond_item_freqs.items() if cnt >= min_support_count}
        if not cond_frequent:
            continue

        # Build conditional FP-tree
        cond_tree = FPTree()
        for it, cnt in cond_frequent.items():
            cond_tree.header_table[it] = [cnt, None]

        def cond_sort_key(it):
            return (-cond_frequent[it], it)

        for path, path_cnt in conditional_patterns.items():
            filtered_path = [it for it in path if it in cond_frequent]
            if filtered_path:
                filtered_path.sort(key=cond_sort_key)
                cond_tree.insert_transaction(filtered_path, count=path_cnt)

        diagnostics['conditional_trees_count'] += 1

        if detailed_examples is not None and track_items and item in track_items and len(prefix) == 0:
            detailed_examples[item]['conditional_fp_tree'] = cond_tree.root.to_dict()

        # Recursive call
        mine_fp_tree(
            cond_tree,
            new_prefix,
            min_support_count,
            frequent_itemsets,
            diagnostics,
            N_total
        )


def fpgrowth_first_principles(
    transactions: List[Set[str]],
    min_support: float = 0.10,
    min_confidence: float = 0.20,
    track_example_items: Optional[Set[str]] = None
) -> Dict[str, Any]:
    """
    Main entry point for FP-Growth from first principles.
    """
    N = len(transactions)
    # Using small epsilon (1e-7) so rounded inputs (e.g. 2/30) are not excluded by 2.001
    min_support_count = (min_support * N) - 1e-7

    diagnostics = {
        'fp_tree_nodes_count': 0,
        'header_table_entries': 0,
        'conditional_pattern_bases_count': 0,
        'conditional_trees_count': 0,
        'recursive_mining_calls': 0
    }

    # Convert sets to lists
    tx_lists = [list(tx) for tx in transactions]

    # Step 1-4: Build main FP-Tree
    main_fp_tree, item_frequencies = build_fp_tree(tx_lists, min_support_count)

    if main_fp_tree is not None:
        diagnostics['fp_tree_nodes_count'] = main_fp_tree.count_nodes()
        diagnostics['header_table_entries'] = len(main_fp_tree.header_table)

    frequent_itemsets: Dict[frozenset, Dict[str, Any]] = {}
    detailed_examples: Dict[str, Any] = {}

    # Step 5-8: Mine frequent itemsets
    mine_fp_tree(
        main_fp_tree,
        prefix=set(),
        min_support_count=min_support_count,
        frequent_itemsets=frequent_itemsets,
        diagnostics=diagnostics,
        N_total=N,
        detailed_examples=detailed_examples,
        track_items=track_example_items
    )

    # Step 9-10: Generate association rules
    rules = []
    for itemset, meta in frequent_itemsets.items():
        if len(itemset) < 2:
            continue

        itemset_count = meta['count']
        itemset_supp = meta['support']
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

    # Sort rules deterministically: confidence desc, lift desc, support desc
    rules.sort(key=lambda r: (-r['confidence'], -r['lift'], -r['support'], r['antecedent'], r['consequent']))

    return {
        'N_transactions': N,
        'min_support': min_support,
        'min_confidence': min_confidence,
        'min_support_count': min_support_count,
        'main_fp_tree': main_fp_tree.root.to_dict() if main_fp_tree else None,
        'header_table': {
            it: [val[0], val[1].node_id if val[1] else None]
            for it, val in main_fp_tree.header_table.items()
        } if main_fp_tree else {},
        'frequent_itemsets': frequent_itemsets,
        'rules': rules,
        'diagnostics': diagnostics,
        'detailed_examples': detailed_examples
    }


def get_transactions_from_matrix(df) -> List[Set[str]]:
    """Convert binary incidence DataFrame into list of item sets."""
    transactions = []
    for _, row in df.iterrows():
        basket = set(row.index[row == 1])
        transactions.append(basket)
    return transactions
