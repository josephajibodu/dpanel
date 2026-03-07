# Realtime Events Architecture

This document describes how realtime WebSocket updates are organized in the application. We use **per-resource hooks composed from shared primitives**—not a single generic solution.

## Design Principles

- **No single solution**: Each resource (servers, deployments, etc.) has its own hooks tailored to its needs. We do not use one generic `useRealtime(channel, events, options)` hook.
- **Shared primitives**: Common logic (channel subscription, connection state, polling fallback) lives in small, focused hooks that resource-specific hooks compose.
- **Explicit over generic**: Hooks are named by resource and purpose (e.g. `useServerProvisioningUpdates`, `useDeploymentLogs`) for clarity and easier maintenance.

---

## Channel Naming

- **List**: `servers.{userId}` (plural) — user's collection of servers
- **Single**: `server.{serverId}` (singular) — one server

Apply the same plural/singular pattern to other resources (e.g. `sites.{userId}`, `site.{siteId}`).

---

## Update Types

| Type | Channel shape | Event pattern | Frontend reaction |
|------|---------------|---------------|-------------------|
| **Status** | `server.{id}`, `deployments.{id}` | `.resource.status.changed` | Merge into state or partial reload |
| **Log stream** | `deployments.{id}` | `.output` | Append to local state |
| **List** | `servers.{userId}` | `.server.status.changed`, `.server.deleted` | Partial reload |

---

## File Structure

```
resources/js/
├── hooks/
│   ├── realtime/                    # Shared primitives
│   │   ├── use-echo-channel.ts      # Subscribe, connection state, cleanup
│   │   ├── use-channel-event.ts     # Listen to one event, call handler
│   │   └── use-polling-fallback.ts  # Poll when condition + disconnected
│   │
│   ├── servers/
│   │   ├── use-server-provisioning-updates.ts   # Detail: status + fallback
│   │   └── use-servers-list-updates.ts          # List: reload on events
│   │
│   └── deployments/
│       ├── use-deployment-updates.ts            # Detail: status reload
│       └── use-deployment-logs.ts               # Detail: log stream
```

---

## Primitives

### `useEchoChannel(channelName)`
- Subscribes to a private channel.
- Tracks connection state (`connected` | `connecting` | `disconnected`).
- Binds to Pusher `state_change` for connection status.
- Cleans up on unmount.

### `useChannelEvent(channel, eventName, handler)`
- Listens to a single event on the channel.
- Calls handler when event is received.
- Cleans up on unmount.

### `usePollingFallback(shouldPoll, reloadOptions)`
- When `shouldPoll` is true: runs `router.reload(reloadOptions)` every 5 seconds.
- Clears interval when `shouldPoll` becomes false.
- Optional—only used when live updates are critical during transient states (e.g. provisioning).

---

## Hook Composition

| Hook | Channel | Events | Reaction | Fallback |
|------|---------|--------|----------|----------|
| `useServerProvisioningUpdates` | `server.{id}` | `.server.status.changed` | Merge into state | Yes (when provisioning + disconnected) |
| `useServersListUpdates` | `servers.{userId}` | `.server.status.changed`, `.server.deleted` | `router.reload({ only: ['servers'] })` | Optional |
| `useDeploymentUpdates` | `deployments.{id}` | `.deployment.status.changed` | `router.reload({ only: ['deployment'] })` | Optional |
| `useDeploymentLogs` | `deployments.{id}` | `.output` | Append to state | No |

---

## Naming Conventions

- **`use{Resource}DetailUpdates`** — Single resource, status changes.
- **`use{Resource}ListUpdates`** — List of resources, reload on events.
- **`use{Resource}Logs`** — Streaming logs or other incremental data.

---

## Adding a New Resource

1. Add channel in `routes/channels.php` using plural for list (`resources.{userId}`) and singular for detail (`resource.{resourceId}`).
2. Create broadcast events (e.g. `SiteStatusChanged`).
3. Create `use-{resource}-detail-updates.ts` using `useEchoChannel` + `useChannelEvent`.
4. Optionally add `use-{resource}-list-updates.ts` for list pages.
5. Add polling fallback only when live updates during transient states are critical.
