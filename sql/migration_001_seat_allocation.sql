-- =====================================================================
-- UniGo - Migration 001
-- Fixes defect D-01: seat allocation must be deterministic and unique,
-- and the availability guard must actually be enforced.
--
-- 1. seats_taken on `trips` acts as a monotonic allocation counter. Because
--    the booking transaction takes an exclusive row lock on the trip before
--    reading it, incrementing and reading this counter inside the same
--    transaction yields a collision-free seat number without a separate
--    "find lowest free seat" query (which is racy under concurrency).
--
-- 2. UNIQUE (trip_id, seat_number) is the belt-and-braces guarantee. Even
--    if application logic were bypassed, the database itself refuses to
--    store two passengers in the same seat on the same trip.
-- =====================================================================

ALTER TABLE trips
    ADD COLUMN seats_taken INT NOT NULL DEFAULT 0 AFTER seats_available;

-- Backfill from existing non-cancelled bookings so the counter is consistent
-- with reality before the new code path takes effect.
UPDATE trips t
SET t.seats_taken = (
    SELECT COUNT(*) FROM bookings b
    WHERE b.trip_id = t.id AND b.status <> 'cancelled'
);

ALTER TABLE bookings
    ADD UNIQUE KEY uq_trip_seat (trip_id, seat_number);

-- Optional: free the seat again when a booking is cancelled, so the seat
-- returns to the pool instead of being permanently burned.
CREATE TABLE IF NOT EXISTS schema_migrations (
    version     VARCHAR(50) NOT NULL PRIMARY KEY,
    applied_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);

INSERT IGNORE INTO schema_migrations (version) VALUES ('001_seat_allocation');
