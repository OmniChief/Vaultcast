"""Rolling measured throughput, excluding restored work and short startup bursts."""
from collections import deque
import math


class RateWindow:
    def __init__(self):
        self.reset()

    def reset(self):
        self.points = deque()
        self.identity = None

    def measure(self, now, position, remaining, identity):
        if not all(isinstance(x, (int, float)) and math.isfinite(x) and x >= 0 for x in (now, position, remaining)):
            self.reset()
            return 0, None
        if identity != self.identity or (self.points and position < self.points[-1][1]):
            self.reset()
            self.identity = identity
        self.points.append((now, position))
        while len(self.points) > 2 and self.points[1][0] < now-30:
            self.points.popleft()
        duration = now-self.points[0][0]
        moved = position-self.points[0][1]
        rate = moved/duration if duration >= 1 and moved > 0 else 0
        # The speed label can update early; an ETA needs a meaningful history.
        eta = remaining/rate if duration >= 10 and rate > 0 else None
        return rate, eta
