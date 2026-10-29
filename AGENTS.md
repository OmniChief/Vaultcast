# Repository instructions

- For each new pull request, advance only the final build component in Application/VERSION (X.Y.Z becomes X.Y.(Z+1)); preserve major and minor components. Synchronize Optional/AIWorker/VERSION, which is shown in the worker manager and console.
- Do not add test files or run functional/test suites without the user asking for them. Syntax checks are authorized. Keep checks and scratch files outside the repository.
- Prefer one focused commit per pull request. Do not commit temporary files, generated caches or downloaded models.
- The contributor manager targets Windows and uses Python/Tkinter. Keep GUI operations on its UI thread, perform network/install/model work in background tasks, and do not discard active task data when applying settings.
- Image and video generation must originate from authenticated jobs claimed from Vaultcast. The manager is for setup, configuration and worker supervision only: no local prompt editor, manual generation, preview generation or generation-based setup checks. Connection and model readiness checks must not generate media. Keep the managed inference engine bound to loopback.
