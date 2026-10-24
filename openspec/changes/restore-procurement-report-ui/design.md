# Scope and mapping

| Existing capability | Result |
| --- | --- |
| Overview title marketing | Remains removed |
| Counts and filters | Retained above report |
| Project link | Teal anchor to inline article; standalone route retained in article |
| Unit and publication date in table | Full raw values in article |
| Method and category columns | Method plus subtype in purchase nature; category plus source under title |
| Quantity and dates | Same raw values, report columns and article date strip |
| Issues and invited state | Visible warnings and scope indicators |
| Pending entries | Separate pending section, never hidden or counted as confirmed |
| AI, evidence and upload batch | Article supplementary links and source section |
| Mobile cards | Responsive report table with contained horizontal scroll; stacked article dates |

Shared article rendering keeps overview and standalone detail consistent. Scoped CSS copies the report colors and spacing without changing global styles. Server ordering happens before pagination: priority P1/P2/P3/pending, newest batch created_at, then notice id ascending for source order. Source and batch ids are database ids, never HTML supplied by the uploaded payload. Tests cover links resolving to articles, unsafe content, raw detail fields, filtering, pending visibility and permission-preserving ordering.
