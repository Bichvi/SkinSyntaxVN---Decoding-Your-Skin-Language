"""Tiny in-memory Mongo double for state-transition tests (no mongomock dependency).

Supports the operators the service uses: equality, $in, $nin, $ne, $lt, $lte, $gt, $gte, $regex, $exists,
delete_one/delete_many and insert_many(ordered=...),
$set, $inc, $unset, $push($each/$slice), $addToSet and $pull (single-value forms), upsert+$setOnInsert,
bulk_write(UpdateOne), sort/limit and the find_one_and_update/update_many helpers.
Like Mongo, an equality filter on an array field matches when the array contains the value.
"""
from __future__ import annotations

import copy
import re
from types import SimpleNamespace

from bson import ObjectId


def _match_value(actual, condition):
    if isinstance(condition, dict) and any(key.startswith("$") for key in condition):
        for op, expected in condition.items():
            if op == "$in" and actual not in expected:
                return False
            if op == "$nin" and actual in expected:
                return False
            if op == "$ne" and actual == expected:
                return False
            if op == "$lt" and not (actual is not None and actual < expected):
                return False
            if op == "$lte" and not (actual is not None and actual <= expected):
                return False
            if op == "$gt" and not (actual is not None and actual > expected):
                return False
            if op == "$gte" and not (actual is not None and actual >= expected):
                return False
            if op == "$regex" and not (isinstance(actual, str) and re.search(expected, actual)):
                return False
            if op == "$exists" and (actual is not None) != bool(expected):
                return False
        return True
    if isinstance(actual, list) and not isinstance(condition, (list, dict)):
        return condition in actual
    return actual == condition


def _get(doc, dotted):
    value = doc
    for part in dotted.split("."):
        if not isinstance(value, dict) or part not in value:
            return None
        value = value[part]
    return value


def _set(doc, dotted, value):
    parts = dotted.split(".")
    for part in parts[:-1]:
        doc = doc.setdefault(part, {})
    doc[parts[-1]] = value


def matches(doc, query):
    for key, cond in (query or {}).items():
        if key == "$or":
            if not any(matches(doc, branch) for branch in cond):
                return False
        elif not _match_value(_get(doc, key), cond):
            return False
    return True


class Cursor(list):
    def sort(self, key, direction=1):
        return Cursor(sorted(self, key=lambda item: _get(item, key), reverse=direction < 0))

    def limit(self, count):
        return Cursor(self[:count])


class Collection:
    def __init__(self, docs=None):
        self.docs = [copy.deepcopy(doc) for doc in (docs or [])]
        self.writes = []

    def find(self, query=None, projection=None):
        return Cursor(self.docs[i] for i in range(len(self.docs)) if matches(self.docs[i], query))

    def find_one(self, query=None, projection=None, **kwargs):
        found = self.find(query)
        return found[0] if found else None

    def count_documents(self, query):
        return len(self.find(query))

    def _apply(self, doc, update):
        for key, value in update.get("$set", {}).items():
            _set(doc, key, value)
        for key, value in update.get("$inc", {}).items():
            _set(doc, key, (_get(doc, key) or 0) + value)
        for key in update.get("$unset", {}):
            _set(doc, key, None)
        for key, value in update.get("$push", {}).items():
            items = _get(doc, key) or []
            if isinstance(value, dict) and "$each" in value:
                items = items + list(value["$each"])
                if "$slice" in value:
                    items = items[value["$slice"]:] if value["$slice"] < 0 else items[:value["$slice"]]
            else:
                items = items + [value]
            _set(doc, key, items)
        for key, value in update.get("$addToSet", {}).items():
            items = list(_get(doc, key) or [])
            _set(doc, key, items if value in items else items + [value])
        for key, value in update.get("$pull", {}).items():
            _set(doc, key, [item for item in (_get(doc, key) or []) if item != value])

    def update_one(self, query, update, **kwargs):
        self.writes.append(("update_one", query, update))
        doc = self.find_one(query)
        if doc is None:
            if not kwargs.get("upsert"):
                return SimpleNamespace(modified_count=0, matched_count=0)
            doc = {key: value for key, value in query.items() if not isinstance(value, dict)}
            self.docs.append(doc)
            for key, value in update.get("$setOnInsert", {}).items():
                _set(doc, key, value)
        self._apply(doc, update)
        return SimpleNamespace(modified_count=1, matched_count=1)

    def update_many(self, query, update, **kwargs):
        self.writes.append(("update_many", query, update))
        found = self.find(query)
        for doc in found:
            self._apply(doc, update)
        return SimpleNamespace(modified_count=len(found), matched_count=len(found))

    def find_one_and_update(self, query, update, **kwargs):
        self.writes.append(("find_one_and_update", query, update))
        doc = self.find_one(query)
        if doc is None:
            return None
        self._apply(doc, update)
        return doc

    def bulk_write(self, requests, ordered=True):
        for op in requests:  # pymongo UpdateOne keeps its arguments in these attributes
            self.update_one(op._filter, op._doc, upsert=op._upsert)
        return SimpleNamespace(matched_count=len(requests), upserted_count=0, modified_count=len(requests))

    def insert_one(self, doc):
        doc.setdefault("_id", ObjectId())  # like pymongo, the caller's dict receives the id
        self.docs.append(copy.deepcopy(doc))
        return SimpleNamespace(inserted_id=doc["_id"])

    def insert_many(self, docs, ordered=True):
        return SimpleNamespace(inserted_ids=[self.insert_one(doc).inserted_id for doc in docs])

    def delete_many(self, query=None):
        self.writes.append(("delete_many", query, None))
        doomed = [id(doc) for doc in self.find(query)]
        self.docs = [doc for doc in self.docs if id(doc) not in doomed]
        return SimpleNamespace(deleted_count=len(doomed))

    def delete_one(self, query=None):
        self.writes.append(("delete_one", query, None))
        found = self.find_one(query)
        if found is not None:
            self.docs = [doc for doc in self.docs if doc is not found]
        return SimpleNamespace(deleted_count=int(found is not None))


class FakeDb:
    """Collections are created on first use, so any ``db.some_collection`` works."""

    def __init__(self, **collections):
        self._collections = dict(collections)

    def __getattr__(self, name):
        if name.startswith("_"):
            raise AttributeError(name)
        return self._collections.setdefault(name, Collection())

    def command(self, *args, **kwargs):
        return {"ok": 1}
