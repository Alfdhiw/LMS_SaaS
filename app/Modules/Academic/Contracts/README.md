# Academic contracts and resource boundaries

Academic owns `student_profiles`, `teacher_profiles`, `academic_years`, `semesters`,
`subjects`, `classrooms`, `classroom_enrollments`, `courses`, `course_teachers` and
`course_enrollments`. PR1 prepares contracts and tests only; there are no Academic
models, services, bindings, application routes or admin UI yet.

Academic imports only public `Contracts`/`Data` from Identity and Tenancy, never
their models/services or direct table writes. Identity/Tenancy do not import Academic;
Academic does not import future Learning/Assessment or Platform. Approved central
read summaries later use only Academic Contracts/Data and return readiness booleans.

Before protected work, the adapter resolves a trusted host and the workflow checks
tenant, user, membership, both auth versions and permission. A membership DTO is a
snapshot of facts, not authority: profile status and teacher/student relationships
remain Academic checks. School admin and platform staff are not implicit teachers.

Resource routes use ULIDs. Binding must obtain the verified context before the first
resource query, then constrain `tenant_id` and `public_ulid` together. Never fetch by
ID/ULID unscoped and filter afterwards; foreign/missing resources return 404 without
metadata. Domain writes repeat authorization after the fresh tenant lock and audit
inside the same transaction. PR2 must exercise this pipeline with real HTTP tests;
PR1 has no route whose access can be claimed as tested.

Use immutable `MembershipSelection` batches for IDs already authorized by the
workflow; do not use the reader as a global directory. All SQL for Academic tables
belongs to Academic. Cross-module identity/profile changes use the owner's ports.
Do not bind unimplemented interfaces or call them until the PR2 adapters exist.
