# Sync API Operations & Monitoring Guide

This document outlines the operations, observability, performance monitoring, and administrative actions for the Squirby Sync API.

## Observability & Request Logging

Every request sent to the `/api/sync/*` endpoints is logged prior to any processing. This serves as a safety net to ensure that client data is durably captured on the server, even if downstream application logic encounters an error.

### Log File Location
Logs are written to daily rotating files located at:
```
storage/logs/sync/requests-YYYY-MM-DD.log
```

### Log Format
Each line in the log file is a single JSON object containing the following structure:
```json
{
  "ts": "2026-06-15T03:00:00-07:00",
  "ip": "127.0.0.1",
  "method": "POST",
  "path": "api/sync/logs",
  "status": 200,
  "duration_ms": 45,
  "query": {}
}
```

### Finding Failed Requests
Since the request logging captures the response status code, failed requests can be identified by filtering for non-200 statuses:
```bash
grep -v '"status":200' storage/logs/sync/requests-$(date +%Y-%m-%d).log
```

Additional methods:
1. Auditing the main application logs (`storage/logs/laravel.log`) for exceptions referencing `/api/sync/*` routes.
2. Checking client-side logs for HTTP status codes >= 400.
3. Re-submitting logged requests through the replay command (e.g. via dry-run) to inspect response status codes.

---

## Performance Monitoring

Response time is logged on every sync request via the `duration_ms` field.

### Quick Commands

**Slowest requests today:**
```bash
grep '"duration_ms"' storage/logs/sync/requests-$(date +%Y-%m-%d).log | sort -t: -k2 -n | tail -10
```

**Average response time for the changes endpoint:**
```bash
grep "changes" storage/logs/sync/requests-*.log | grep -oP '"duration_ms":\K[0-9]+' | awk '{s+=$1; n++} END {print s/n "ms avg over " n " requests"}'
```

**Requests exceeding 500ms:**
```bash
grep '"duration_ms"' storage/logs/sync/requests-*.log | awk -F'"duration_ms":' '{split($2,a,","); if(a[1]+0 > 500) print}'
```

### Thresholds

| Endpoint | Expected | Investigate | Critical |
|----------|----------|-------------|----------|
| `POST /logs` | <50ms | >200ms | >500ms |
| `GET /changes` | <100ms | >500ms | >1000ms |
| `GET /restore` | <200ms | >1000ms | >3000ms |
| `POST /blueprint` | <50ms | >200ms | >500ms |

### When to Optimize

The `GET /changes` endpoint fetches all logs for a user without pagination. At current scale (<500 logs per user) this is fine. When any of the following occur, reintroduce the `since` timestamp cursor:
- Any single `/changes` request exceeds 500ms consistently
- A user exceeds 1000 logs in the database
- The response payload exceeds 500KB

---

## Replaying Requests

If a request failed or needs to be audited, it can be replayed using the `sync:replay-failed` Artisan command. This command parses the sync requests log, filters according to criteria, and dispatches them through the application's HTTP kernel without making real network requests.

### Usage
```bash
php artisan sync:replay-failed [options]
```

### Options
- `--days=N`: Scan logs from the last `N` days (default: `1`).
- `--endpoint=substring`: Filter requests matching the path (e.g., `--endpoint=logs`).
- `--user=identifier`: Filter requests by User ID or username.
- `--dry-run`: Preview matching requests without executing them.

### Examples
To preview all requests from today:
```bash
php artisan sync:replay-failed --dry-run
```

To replay requests from the last 3 days for a specific user:
```bash
php artisan sync:replay-failed --days=3 --user=john_doe
```

---

## Purging Old Logs

To prevent log files from consuming excessive disk space, a purging command is provided to clean up logs older than a specified age.

### Usage
```bash
php artisan sync:purge-logs [options]
```

### Options
- `--days=N`: Delete sync log files older than `N` days (default: `30`).

### Example
To purge logs older than 14 days:
```bash
php artisan sync:purge-logs --days=14
```

---

## Exercise Auto-Creation

When a client submits a completion log, the API's **Exercise Resolver** will resolve it to a global exercise. If the exercise does not exist, a new global exercise is automatically created.

### Optional canonical_name Field
Clients can send an optional `canonical_name` field (e.g. direct client slug) alongside the required `exercise_name` field in `POST /api/sync/logs`. When provided, this slug is used directly for the canonical name lookup.

### Matching Priority
1. Exact match on `exercises.canonical_name` (using `canonical_name` if provided, otherwise falling back to `Str::snake(exercise_name)`).
2. Case-insensitive match on `exercises.title` (using `exercise_name`).
3. Case-insensitive match on global `exercise_aliases.alias_name` (using `exercise_name`, restricted to global aliases with `user_id = NULL`).
4. Auto-creation (using `canonical_name` for the canonical name and `exercise_name` for the title).

### Identification
Auto-created exercises are created as global exercises:
- `user_id = NULL`
- `show_in_feed = true`
- They have a `created_at` timestamp corresponding to a sync request and lack metadata in the `exercise_intelligence` table.

### Merging Duplicates
If a user syncs an exercise with a typo or slight variation, it may result in a duplicate exercise. Administrators can resolve this by using the admin exercise merge feature to merge the auto-created exercise into the canonical global exercise.

When a merge is performed, the system automatically creates a global alias (`user_id = NULL`) on the target exercise with the source exercise's `canonical_name` (unless it is redundant with the title or already exists). This prevents future duplicate creation from the same client slug.

**Workflow**:
1. **Duplicate Auto-Created**: Client sends a sync request with `canonical_name` = `'benchpress'` and `exercise_name` = `'Benchpress'`, creating a duplicate exercise.
2. **Admin Merge**: An administrator merges `'Benchpress'` into the canonical global exercise `'Barbell Bench Press'`.
3. **Alias Created**: A global alias with `alias_name` = `'benchpress'` is automatically created on `'Barbell Bench Press'`.
4. **Future Resolution**: Future sync requests with `canonical_name` = `'benchpress'` resolve directly to `'Barbell Bench Press'` without creating duplicates.

---

## Rate Limiting

The API is protected by a two-tier rate limiter to prevent clients or network retry loops from overwhelming the database.

### Limits
1. **Per-user limit**: 30 requests per minute.
2. **Global limit**: 60 requests per minute across all users.

### Throttled Response (HTTP 429)
When a client exceeds either limit, the API returns:
- Status Code: `429 Too Many Requests`
- Body:
  ```json
  {
    "status": "error",
    "message": "Too many requests. Try again in X seconds."
  }
  ```
- Headers:
  - `Retry-After`: Number of seconds the client must wait before retrying.

---

## Idempotency Key Usage

To handle client retries safely without generating duplicate logs, the `POST /api/sync/logs` endpoint supports idempotency keys.

### Usage
1. The client should generate a unique UUID for each completion log and send it in either:
   - The `X-Idempotency-Key` HTTP header.
   - The `idempotency_key` field in the request body.
2. When the key is received, the API checks for an existing log for that user matching the key.
3. If a match is found, the API returns the existing log's ID with a `200 OK` status and skips database writes.
4. If no key is provided, a new log is created every time.


---

## Pull Sync (Changes Endpoint)

The `GET /api/sync/changes` endpoint returns all logs for the authenticated user. The Athlete app calls this on every page load and tab-visible event to reconcile local state with the server.

### Response Format
```json
{
  "status": "ok",
  "logs": [
    {
      "id": 1234,
      "exerciseId": "back_squat",
      "exerciseName": "Back Squat",
      "date": "2026-06-15",
      "logType": "barbell",
      "sets": [{"weight": 135, "reps": 5}],
      "note": null,
      "weightUnit": "lbs",
      "updated_at": "2026-06-15T12:00:00+00:00",
      "track": "peak",
      "blockIndex": 0,
      "movementIndex": 0
    }
  ],
  "deleted_ids": [336, 341]
}
```

### Merge Strategy (Client-Side)
The Athlete app uses per-log `updated_at` timestamps (Logger clock only) for last-write-wins merge:
1. For each server log, derive the composite key
2. If the local `laravelLogTimestamps[key]` is >= server's `updated_at`, skip (local is same or newer)
3. Otherwise, overwrite local with server version
4. Safeguard: never overwrite a local log with sets with a server log that has empty sets

### Log Type Resolution (Server-Side)
When serializing logs for the response, the `logType` field is determined by:
1. `LiftLog.log_type` (set when synced from Athlete)
2. `Exercise.log_type` (the Athlete-canonical type, populated by ExerciseResolverService)
3. Fallback: coarse mapping from `Exercise.exercise_type` (`cardio` → `cardio`, `static_hold` → `static-hold`, `bodyweight` → `bodyweight-reps`, default → `barbell`)

---

## Exercise Log Type Alignment

The `exercises.log_type` column stores the Athlete-canonical log type (one of 15 values). This is more granular than Logger's internal `exercise_type` (6 values) and determines how sets are serialized/deserialized.

### Athlete Log Types
`barbell`, `single-dumbbell`, `dual-dumbbell`, `bodyweight`, `bodyweight-reps`, `kettlebell`, `static-hold`, `weighted-carry`, `dual-kettlebell`, `cardio`, `cardio-calories`, `cardio-distance`, `added-weight`, `ball`, `banded`

### How It Gets Populated
- On sync: `ExerciseResolverService` sets `log_type` on the exercise when it's first resolved via sync (if not already set)
- Migration backfill: initial migration populated obvious mappings from `exercise_type`
- Exercises created only through Logger's UI may have `log_type = NULL` until synced

### Querying Exercises Missing Log Type
```sql
SELECT id, title, canonical_name, exercise_type, log_type
FROM exercises
WHERE log_type IS NULL
ORDER BY exercise_type, title;
```

To manually set a log type:
```sql
UPDATE exercises SET log_type = 'cardio-calories' WHERE canonical_name = 'cardio';
```

---

## Upsert Behavior

When a `POST /api/sync/logs` request is received, the store action:
1. Checks idempotency key (exact retry deduplication)
2. Checks for existing log at the same slot (user + exercise + date + track + block_index + movement_index)
3. If slot match found: updates the existing record and replaces its sets
4. If no match: creates a new LiftLog + LiftSets

This ensures that editing and re-saving a log on the Athlete side updates the existing record rather than creating duplicates.
