# Server-Authoritative Watch Session & Validation Architecture

## 1. Problem Statement
Client-side video playback events (such as HTML5 `onended`, `ontimeupdate`, or JavaScript variables) are trivial to bypass using browser developer tools or fake fetch requests. 
A naive approach that rewards a customer simply because the browser POSTs `{ "completed": true }` or skips `video.currentTime = 9999` is fundamentally vulnerable to exploitation.

## 2. Server-Authoritative State Machine
In Phase V2, watch sessions will be tracked server-side in a dedicated collection `watch_sessions`:
```json
{
  "_id": ObjectId("..."),
  "session_token": "cryptographically_secure_random_hex_64",
  "customer_id": "63001",
  "publication_id": "6acc6bd54e1a7ffa0e0672b2",
  "video_id": "6acc27e526e9d8acf59c457e",
  "reward_campaign_id": "...",
  "video_duration": 73.816,
  "required_watch_seconds": 65.0,
  "accumulated_watch_seconds": 0.0,
  "last_heartbeat_at": ISODate("2026-10-12T07:00:00Z"),
  "last_media_time": 0.0,
  "heartbeat_count": 0,
  "status": "WATCHING",
  "created_at": ISODate("2026-10-12T07:00:00Z"),
  "expires_at": ISODate("2026-10-12T07:15:00Z")
}
```

## 3. Heartbeat Protocol & Anti-Cheat Rules
1. **Session Initialization (`POST /api/watch-session/start`)**:
   - Requires an authenticated customer session.
   - Validates that the customer has not already claimed the reward for this campaign.
   - Generates a single-use cryptographically random `session_token`.
2. **Periodic Heartbeats (`POST /api/watch-session/heartbeat`)**:
   - Sent every 5 seconds by the video player.
   - Payload: `{ "session_token": "...", "current_time": 15.2, "playback_rate": 1.0 }`.
3. **Server Validation Checks**:
   - **Wall-clock delta check**: If time elapsed since `last_heartbeat_at` is 5.0 seconds, delta between `current_time` and `last_media_time` cannot exceed `5.0 * playback_rate + 1.0s tolerance`.
   - **Monotonic progression**: Client cannot report backwards jumps or massive forwards leaps (e.g., from 5s to 70s in a 5s window). If a jump exceeds 1.5x allowable wall-clock time, accumulated watch credit does not increase.
   - **Playback Rate Ceiling**: Maximum allowed playback rate is capped server-side at 2.0x.
   - **Replay Protection**: Each heartbeat updates `last_heartbeat_at` and `heartbeat_count`; old requests cannot be replayed.
4. **Completion & Claiming (`POST /api/watch-session/claim`)**:
   - Server checks `accumulated_watch_seconds >= required_watch_seconds` AND `status == 'ELIGIBLE'`.
   - Frontend reporting 100% without valid monotonic heartbeats is rejected.
