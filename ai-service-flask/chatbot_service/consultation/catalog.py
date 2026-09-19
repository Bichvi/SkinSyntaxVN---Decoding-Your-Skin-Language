"""Retrieve candidates, then hydrate current catalog facts from MongoDB."""
import logging
import re

from .context import product_metadata
from .request_rules import required_ingredients

logger = logging.getLogger(__name__)


class CatalogUnavailable(RuntimeError):
    pass


class MongoCatalog:
    def __init__(self, pipeline, document_factory, collection):
        self.pipeline = pipeline
        self.document_factory = document_factory
        self.collection = collection

    def document(self, metadata):
        return self.document_factory(page_content=str(metadata.get('mo_ta') or ''),
                                     metadata=metadata, id='product_' + str(metadata.get('id') or ''))

    def hydrate(self, docs):
        ids = list(dict.fromkeys(str(d.id).removeprefix('product_') for d in docs))
        values = ids + [int(i) for i in ids if i.isdigit()]
        try:
            rows = self.collection.find({'ma_san_pham': {'$in': values}}, {'_id': 0})
            by_id = {str(r.get('ma_san_pham')): r for r in rows}
        except Exception as exc:
            raise CatalogUnavailable('Catalog facts unavailable') from exc
        # Missing/deleted products must not survive via stale index or PHP metadata.
        return [self.document(product_metadata(by_id[i])) for i in ids if i in by_id]

    def search(self, query, filters=None, limit=20):
        docs = []
        if self.pipeline is not None and not (filters or {}).get('id'):
            try:
                ranked, _ = self.pipeline.search(query, k_total=max(limit * 3, 30),
                                                 top_n=limit, filters=filters, use_reranker=False)
                docs = [self.document_factory(page_content=d.content, metadata=d.metadata, id=d.doc_id)
                        for d in ranked]
            except Exception as exc:
                logger.warning('catalog.vector_degraded type=%s', type(exc).__name__)
        mongo_filter = dict(filters or {})
        if 'id' in mongo_filter:
            pid = str(mongo_filter.pop('id')).removeprefix('product_')
            mongo_filter['ma_san_pham'] = {'$in': [pid, int(pid)] if pid.isdigit() else [pid]}
        active = required_ingredients(query)
        if active:
            from .ingredients import INGREDIENT_ALIASES
            mongo_filter['$and'] = [
                {'$or': [{field: {'$regex': '|'.join(re.escape(a) for a in INGREDIENT_ALIASES.get(name, (name,))), '$options': 'i'}}
                         for field in ('ten_san_pham', 'thanh_phan_chinh', 'thanh_phan_day_du')]}
                for name in active
            ]
        try:
            # Expand small category/active pools before exclusions, rather than
            # declaring no matches after filtering a tiny semantic top-k.
            direct_limit = 200 if mongo_filter else limit
            rows = list(self.collection.find(mongo_filter, {'_id': 0}).limit(direct_limit))
            docs += [self.document(product_metadata(r)) for r in rows]
            return self.hydrate(docs)
        except CatalogUnavailable:
            raise
        except Exception as exc:
            raise CatalogUnavailable('Catalog lookup unavailable') from exc
