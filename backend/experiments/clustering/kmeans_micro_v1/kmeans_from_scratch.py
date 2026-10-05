"""
K-Means Clustering from First Principles for SkinSyntaxVN Research.

Controlled micro-experiment implementation:
- Deterministic initialization
- Explicit Euclidean distance computation
- Deterministic tie-breaking (argmin selects lowest index)
- Centroid update via arithmetic mean
- WCSS (inertia) tracking
"""

import numpy as np
from typing import Optional, Union, List


class KMeansFromScratch:
    """
    K-Means clustering algorithm built from first principles (no sklearn dependency).
    """

    def __init__(self, n_clusters: int = 3, max_iter: int = 10, tol: float = 1e-6, random_state: int = 42):
        self.n_clusters = n_clusters
        self.max_iter = max_iter
        self.tol = tol
        self.random_state = random_state

        self.cluster_centers_ = None  # shape: (n_clusters, n_features)
        self.labels_ = None           # shape: (n_samples,)
        self.inertia_ = 0.0           # float: Within-Cluster Sum of Squares (WCSS)
        self.n_iter = 0               # int: Number of iterations performed
        self.converged = False        # bool: True if assignments stopped changing
        self.history = []             # list of dicts tracking iteration metrics

    def _compute_distances(self, X: np.ndarray, centroids: np.ndarray) -> np.ndarray:
        """
        Compute Euclidean distance matrix between data points X and centroids.
        
        d(x_i, c_k) = sqrt( sum_j (x_ij - c_kj)^2 )
        
        Returns:
            distances: shape (n_samples, n_clusters)
        """
        n_samples = X.shape[0]
        n_clusters = centroids.shape[0]
        distances = np.zeros((n_samples, n_clusters), dtype=float)

        for i in range(n_samples):
            for k in range(n_clusters):
                diff = X[i] - centroids[k]
                distances[i, k] = np.sqrt(np.sum(diff ** 2))

        return distances

    def _compute_wcss(self, X: np.ndarray, labels: np.ndarray, centroids: np.ndarray) -> float:
        """
        Compute within-cluster sum of squares (WCSS / Inertia):
        J = sum_k sum_{x_i in C_k} ||x_i - mu_k||^2
        """
        wcss = 0.0
        for i in range(len(X)):
            k = labels[i]
            diff = X[i] - centroids[k]
            wcss += np.sum(diff ** 2)
        return float(wcss)

    def fit(self, X: Union[np.ndarray, List], initial_centroids: Optional[np.ndarray] = None) -> 'KMeansFromScratch':
        """
        Fit K-Means on feature matrix X.
        
        Args:
            X: Array-like of shape (n_samples, n_features)
            initial_centroids: Optional pre-defined initial centroids of shape (n_clusters, n_features)
        """
        X = np.asarray(X, dtype=float)
        n_samples, n_features = X.shape

        if initial_centroids is not None:
            centroids = np.array(initial_centroids, dtype=float).copy()
            if centroids.shape != (self.n_clusters, n_features):
                raise ValueError(f"initial_centroids shape {centroids.shape} must match ({self.n_clusters}, {n_features})")
        else:
            # Deterministic selection if no initial centroids provided
            rng = np.random.RandomState(self.random_state)
            indices = rng.choice(n_samples, size=self.n_clusters, replace=False)
            centroids = X[indices].copy()

        self.cluster_centers_ = centroids
        prev_labels = None
        self.history = []

        for it in range(1, self.max_iter + 1):
            self.n_iter = it

            # 1. Compute Euclidean distances to each centroid
            distances = self._compute_distances(X, self.cluster_centers_)

            # 2. Assign to nearest centroid (np.argmin breaks ties by choosing lowest index)
            labels = np.argmin(distances, axis=1)

            # 3. Compute WCSS for current assignment and centroids
            wcss = self._compute_wcss(X, labels, self.cluster_centers_)

            self.history.append({
                'iteration': it,
                'centroids': self.cluster_centers_.copy(),
                'labels': labels.copy(),
                'wcss': wcss
            })

            # 4. Check convergence (assignments stopped changing)
            if prev_labels is not None and np.array_equal(labels, prev_labels):
                self.converged = True
                self.labels_ = labels
                self.inertia_ = wcss
                break

            prev_labels = labels.copy()

            # 5. Update centroids: arithmetic mean of assigned points
            new_centroids = np.zeros((self.n_clusters, n_features), dtype=float)
            for k in range(self.n_clusters):
                cluster_points = X[labels == k]
                if len(cluster_points) > 0:
                    new_centroids[k] = np.mean(cluster_points, axis=0)
                else:
                    # Keep old centroid if cluster is empty
                    new_centroids[k] = self.cluster_centers_[k]

            self.cluster_centers_ = new_centroids
            self.labels_ = labels
            self.inertia_ = self._compute_wcss(X, labels, self.cluster_centers_)

        # Final check if loop exited without breaking
        if not self.converged and prev_labels is not None:
            # Check if final assignment matches
            distances = self._compute_distances(X, self.cluster_centers_)
            final_labels = np.argmin(distances, axis=1)
            if np.array_equal(final_labels, prev_labels):
                self.converged = True
            self.labels_ = final_labels
            self.inertia_ = self._compute_wcss(X, final_labels, self.cluster_centers_)

        return self

    def predict(self, X: Union[np.ndarray, List]) -> np.ndarray:
        """
        Assign new data points to closest fitted centroid.
        """
        X = np.asarray(X, dtype=float)
        distances = self._compute_distances(X, self.cluster_centers_)
        return np.argmin(distances, axis=1)
