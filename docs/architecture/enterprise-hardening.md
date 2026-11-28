# Enterprise Hardening Architecture

## Rail Congestion and Link-Fault Localization

`Somethingwithproof\Fabric\RailCongestionAnalyzer` separates physical link-local
faults from correlated fabric-wide congestion across leaf and spine tiers.

### Academic Grounding

- Guo et al., *Pingmesh: A Large-Scale System for Data Center Network Latency
  Measurement and Analysis*, ACM SIGCOMM 2015: tier-aware correlation to
  distinguish isolated link issues from switch-plane and rail-wide congestion.
- Gangidi et al., *RDMA over Ethernet for Distributed AI Training at Meta
  Scale*, ACM SIGCOMM 2024: rail-optimized AI fabric diagnostics separating
  link-local FEC/symbol errors and flapping ports from ECN marking, PFC pause,
  and XmitWait congestion storms across leaf and spine planes.

### Design Invariants

- **Bounded flap damping state**: exponential-decay flap penalties are held in a
  fixed-capacity table (`DEFAULT_MAX_TRACKED_PORTS = 4096`) with oldest-entry
  eviction so port churn cannot exhaust memory.
- **Fault isolation**: ports with symbol errors, uncorrectable FEC errors, or
  active flap suppression are reported under `link_local_faults` and excluded
  from rail congestion denominators so faulty optics do not masquerade as fabric
  congestion.
- **Cross-tier classification**: storms affecting both `leaf` and `spine` tiers
  on the same rail are reported as `cross_tier_rail_storm`; single-tier storms
  are reported as `tier_rail_storm`.

## Decoupled Plugin Integration and Config/State Separation

- `schema/fabric.schema.yaml` defines strict separation between declarative
  configuration (`config`), the encrypted non-replicated credential vault
  (`secret_vault`), and ephemeral operational state (`state`).
- Optional hooks `doctor_register_debug_info` and `api_register_resources`
  return plain PHP arrays (redacted diagnostic fields and Google AIP resource
  descriptors) without importing any external plugin classes.
