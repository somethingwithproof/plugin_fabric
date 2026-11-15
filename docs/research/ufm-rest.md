# UFM REST API: endpoints, authentication, versions, and roles

Research for [#21](https://github.com/somethingwithproof/plugin_fabric/issues/21), run 2026-11-15, with input to the decision [#12](https://github.com/somethingwithproof/plugin_fabric/issues/12). Question: which UFM Enterprise REST endpoints and authentication methods give read-only access to port inventory, link width and speed, and counters, and how do they differ between releases?

**Result.** The documentation supports the plan's backend design with five changes. (1) The ports resource is documented only by example JSON, with no field table or enumeration of state values; width and speed are strings, not numbers. (2) UFM has no built-in read-only role named as such; the nearest is `Monitoring_Only`, which can open monitoring sessions, and the guide documents custom roles with an allowed-URL list. Which predefined role may call which endpoint is not documented. (3) The guide does not say whether a snapshot leaves server-side state, but a cheaper route exists: UFM's default session (ID 0) holds all port statistics collected every 30 seconds by default and is read with a plain GET. (4) Token authentication has two documented URL forms, and no renewal procedure; the only lifetime shown is an example value. (5) TLS defaults, rate limits, and UFM support dates were not found. Nothing was run against a UFM server; every behavioral claim below is the guide's statement, and each *live* check needs a UFM instance.

## Method

The UFM REST API guide and UFM user manual were fetched from `networking-docs.nvidia.com` (digit paths for releases up to 6.2x, dotted paths for current ones) on 2026-11-14, as text, and read for the endpoints and quotes below. Pages for 6.15.0, 6.19.0, 6.20.0 (path `6200`), 6.21.2, 6.25.1, and 6.26.1 were compared by text. Document revision dates come from the guide's revision history; they are not general availability dates. No account, token, or UFM host was used.

## Releases

| Fact | Result | Status | Source |
|---|---|---|---|
| Guide versions published | 6.15.0, 6.15.1, 6.15.2, 6.15.4, 6.15.6, 6.15.8, 6.16.0, 6.17.0 to 6.17.2, 6.18.0, 6.19.0, 6.19.3, 6.19.4, 6.19.6, 6.20.0 to 6.20.2, 6.21.0, 6.21.2, 6.22.1, 6.22.2, 6.23.1, 6.24.1, 6.24.2, 6.25.1, 6.26.1 | confirmed | [Document revision history](https://networking-docs.nvidia.com/ufmenterpriserestapi/6.26.1/document-revision-history) |
| Latest guide | 6.26.1 (revision dated August 2026) | confirmed | same |
| Support and end-of-life dates for UFM Enterprise releases | NOT FOUND in the guide or manual | unverified | |
| The plan's reference path | The plan links the `6200` path, which is guide version 6.20.0 | confirmed | [Ports (6200)](https://networking-docs.nvidia.com/ufmenterpriserestapi/6200/ports-rest-api) |

Differences the guide records between releases, for the endpoints the plugin would use:

| Release | Change | Source |
|---|---|---|
| 6.15.0 | Complementary information page introduced the authentication methods (basic, session, token, client certificate) | revision history |
| 6.20.0 | Links, Ports, and static-topology pages updated; the port-label note was added (`dname` such as `1/3/1` for XDR and NDR switches and ConnectX-6 or later HCAs); `brief=true` dropped from the example URL | revision history, 6.19.0 and 6.20.0 text compared |
| 6.21.0 | LDAP authentication added to the complementary page | revision history |
| 6.22.1 | Client authentication: refresh certificates endpoint added | revision history |
| 6.25.1 | `site_name` filter shown for ports; inventory summary and count endpoints present under monitoring | 6.25.1 text; they are absent from the 6.19.0 monitoring page |
| 6.26.1 | `GET Plane Ports` (`show_plane=true`) added; session-cookie invalidation note after restart or upgrade | revision history |
| 6.15 to 6.19 | Ports page text identical in 6.15.0, 6.17.0, and 6.19.0 | text comparison |

## Endpoints

| Endpoint | Method | What it gives | Notes | Source |
|---|---|---|---|---|
| `/ufmRest/resources/ports` | GET | All ports; filters `sys_type`, `system`, `active`, `external`, `high_ber_only` (with `high_ber_severity`), `cable_info`, `show_plane` (6.26.1); paging `page_number`, `rpp`, `sorting=<field>[asc]`, `filter=<field>[eq]<value>` | Paged envelope `{total_resources, filtered_resources, num_of_pages, first_index, last_index, data}`. Defaults and maximum for `rpp`, and response size guidance, are NOT FOUND. | [Ports REST API 6.26.1](https://networking-docs.nvidia.com/ufmenterpriserestapi/6.26.1/ports-rest-api) |
| `/ufmRest/resources/ports/<name1>,<name2>` | GET | Ports by name, where the name is `<guid>_<port number>` | Name format from the examples | same |
| Per-port fields | | `description`, `number`, `external_number`, `physical_state`, `logical_state`, `lid`, `capabilities`, `mtu`, `active_speed`, `enabled_speed` (list), `supported_speed` (list), `active_width`, `enabled_width` (list), `supported_width` (list), `dname`, `name`, `guid`, `systemID`, `system_name`, `peer`, `peer_node_name`, `peer_node_guid`, `peer_guid`, `peer_lid`, `peer_port_dname`, `peer_ip`, `hw_technology`, `high_ber_severity`, `severity`, `module`, `tier`, `mirror` | From example JSON only. Values: `"active_speed": "EDR"`, `"active_width": "4x"`, `"enabled_width": ["1x","2x","4x"]`; a down port has `null` and empty lists. The enumeration of `physical_state` and `logical_state` values is NOT FOUND. | same |
| `guid` for adapters | | In the HCA example `guid` equals `systemID`, i.e. a system-level GUID, not a per-port GUID | The port key is the `name` (`<guid>_<number>`). Whether `guid` is the node GUID or the system image GUID on each device type is *live*. | same |
| `/ufmRest/monitoring/attributes` | GET | Functions (`RAW`, `AVG`, `SUM`, `MIN`, `MAX`), classes, and the counter list | Counter names include `Infiniband_SymbolErrors`, `_LinkDowned`, `_LinkRecovers`, `_RcvErrors`, `_XmtDiscards`, `_XmtConstraintErrors`, `_RcvConstraintErrors`, `_LinkIntegrityErrors`, `_ExcBufOverrunErrors`, `_VL15Dropped`, `_RcvRemotePhysErrors`, `_RcvSwRelayErrors`, `Infiniband_XmitWait`, `Infiniband_MBOut`, `_MBIn`, `_PckOut`, `_PckIn`, with `_Delta` and `Rate` variants for many | [Monitoring REST API 6.26.1](https://networking-docs.nvidia.com/ufmenterpriserestapi/6.26.1/monitoring-rest-api) |
| `Infiniband_MBOut` unit | | "Total number of data octets, divided by 4 ... reported as a multiple of four octets": the same unit as `PortXmitData`, not megabytes, despite the name | | same |
| `/ufmRest/monitoring/session/0/data` | GET | "UFM default session which collects all port statistics every 30 seconds (by default)" | No session is created. Each object carries `last_updated`. | same |
| `/ufmRest/monitoring/snapshot` | POST | "creates a one-time monitoring session and receives data" | Whether the session persists or must be deleted: NOT FOUND. Body: `scope_object`, `monitor_object`, `objects`, `attributes` (the format block says `counters`), `functions`, `interval` (unit not stated). | same |
| `/ufmRest/monitoring/start` | POST | "creates and starts a monitoring session"; 201 with a `Location` header | Creates server state; delete with the session `DELETE` (202) | same |
| `/ufmRest/telemetry` | GET | History and top-X over ports, if the telemetry feature is enabled | Not examined | [Telemetry REST API](https://networking-docs.nvidia.com/ufmenterpriserestapi/6.26.1/telemetry-rest-api) |
| `/ufmRest/resources/links`, `systems`, `modules`, non-optimal links, unhealthy ports | GET | Exist in the guide navigation | Not examined; the non-optimal links page may already encode a degraded-link view | guide navigation |

## Authentication

| Method | URL prefix | How | Notes | Source |
|---|---|---|---|---|
| Basic | `/ufmRest` | user and password, "enabled by default" | The guide's own examples use `curl -k`, so verification is off in the examples | [Authentication methods (user manual)](https://networking-docs.nvidia.com/ufmenterpriseum/6.26.1/authentication-methods), [REST complementary information](https://networking-docs.nvidia.com/ufmenterpriserestapi/6.26.1/rest-api-complementary-information) |
| Session cookie | `/ufmRestV2` | `POST /dologin` with form fields, then a cookie | 6.26.1: restart or upgrade of UFM with the authentication server disabled invalidates cookies | same |
| Token | `/ufmRestV3` per the manual; the REST guide's own example calls `/ufmRest/resources/systems` with the header | `POST /ufmRest/app/tokens` returns `access_token`, `issued_at`, `expires_in`; use `Authorization: Basic <access_token>`; `GET /ufmRest/app/tokens` lists; `POST /ufmRest/app/tokens/revoke` revokes | The only lifetime shown is the example `expires_in: 315360000` (ten years if seconds). No renewal endpoint; revoke and recreate. The prefix inconsistency within the same guide was not resolved. | [Access Tokens API](https://networking-docs.nvidia.com/ufmenterpriserestapi/6.26.1/access-tokens-api) |
| Client certificate | `/ufmRest` | UFM extracts the certificate CN and compares it to a stored value; settings under `/ufmRest/app/client_authentication/settings` (admin operations) | Added in the 6.15 line; refresh endpoint 6.22.1 | [Client authentication API](https://networking-docs.nvidia.com/ufmenterpriserestapi/6.26.1/client-authentication-rest-api) |
| LDAP, TACACS, Kerberos, Azure AD, proxy | various | Listed in the manual | Not examined | user manual |
| TLS defaults, certificate trust, rate limits, lockout | | NOT FOUND in the REST guide | The manual path for the server certificate was seen only as a search hit | |

## Roles and a least-privilege account

| Fact | Result | Status | Source |
|---|---|---|---|
| Built-in groups | `System_Admin`, `Fabric_Admin`, `Fabric_Operator`, `Monitoring_Only`, `Tenant` | confirmed | [Users REST API](https://networking-docs.nvidia.com/ufmenterpriserestapi/6.26.1/users-rest-api) |
| Closest to read-only | `Monitoring_Only`: "users can perform monitoring actions such as view the fabric configuration, open monitoring sessions, define monitoring templates, and export monitoring data to CSV files" | confirmed (manual) | [User management tab](https://networking-docs.nvidia.com/ufmenterpriseum/6.26.1/user-management-tab) |
| No role named Viewer, Guest, or Standard_User exists in these pages | | confirmed absence in the pages read | |
| Which predefined role may call `/resources/ports`, `/monitoring/snapshot`, and `/monitoring/session/<id>/data` | NOT FOUND (no per-role matrix) | unverified; *live* | |
| Custom roles | `GET`, `POST` `/ufmRest/app/roles_access_control/roles`; body `{"name": ..., "allowed_urls": {"<route>": ["GET", ...]}}`; "By default, all URLs are denied"; `GET .../roles_access_control/rest_apis` lists routes. The guide's example role named `Read_only` allows `/monitoring/start` POST, which creates a session. | confirmed (guide); the exact route spellings the plugin needs are *live* | [Roles access control](https://networking-docs.nvidia.com/ufmenterpriserestapi/6.26.1/roles-access-control) |
| Roles feature availability | "exclusively available to System_Admin users" and "can be enabled or disabled through the gv.cfg file. By default, it is enabled." | confirmed (manual) | same family of pages |

Recipe, to confirm live: create a custom role that allows only `GET` on the ports resource and on the default session's data route, create a user in it, and use it from the plugin. If the custom role cannot be made to cover those routes, fall back to `Monitoring_Only` and document that it can open monitoring sessions. The recipe was not tried.

## Input to the credential decision (#12)

- The credential classes UFM accepts are a password (basic), a long-lived token created from a password, and a client certificate. Only the token has no stated renewal path.
- Cacti 1.2.31 has no credential-store function a plugin can call: the plan's assumption is answered. On the current `1.2.x` head (`e2d5c25893221c3cd54ceb8293fba6ca38c936e6`) core has `cacti_encrypt_secret()` and `cacti_decrypt_secret()` (AES-256-GCM, [functions.php:10008-10031](https://github.com/Cacti/cacti/blob/e2d5c25893221c3cd54ceb8293fba6ca38c936e6/lib/functions.php#L10008-L10031)), but they are core helpers added for the shared SNMP auth cache and are absent from `release/1.2.31`, and their key (`secret_encryption_key`) is stored in the `settings` table next to the ciphertext, so a database dump holds both. The plan's minimum is 1.2.31, so a plugin cannot rely on them.
- plugin_cloud's ADR 0007 records that plugin_cloudflare keeps an encrypted vault keyed by a file outside the database. That is a pattern that works at 1.2.31; it was not re-examined here.

## Live checks

| Issue | Check | What it settles |
|---|---|---|
| #21 | Create a custom GET-only role and user on a UFM instance; call the ports resource and session 0 | The least-privilege recipe, and the real route names |
| #21 | Call `POST /ufmRest/monitoring/snapshot` and list `/ufmRest/monitoring` sessions before and after | Whether a snapshot leaves server-side state |
| #21 | Fetch ports at 10,000 ports with several `rpp` values | Page limits and response size |
| #21 | Capture `GET /ufmRest/resources/ports` JSON for an HCA, a switch, a down port, and a downgraded link, and record state strings | The state enumerations, `guid` semantics, and fixtures |
| #21 | Create a token and record its real lifetime; test a token against `/ufmRestV3` and `/ufmRest` | Token lifetime and the correct prefix |
| #21 | Connect to a default-install UFM with certificate verification on | TLS defaults |
| #21 | Compare session 0 values with a direct `perfquery` of the same port | Freshness and units |
| #13 | Record the UFM releases operators actually run and their support dates from NVIDIA | The UFM version matrix |

## Limits

Documentation only, from one fetch pass. Per-release pages for 6.15.x to 6.19.x were compared as text, not read line by line. Links, Systems, Modules, Unhealthy Ports, and Telemetry were not examined in depth. The role numbers in older manuals were not used. The 6.26.1 revision-history date for the latest release is printed with a typo in the source ("2.26.1").
