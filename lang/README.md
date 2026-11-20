Currently there are 2 solutions for multilingualism:
- The old Nexus environment uses translation files for web pages, which are imported into the language file via `require_once(get_langfile_path())` and then used.
- The new ones are defined under `resources/lang` and called via `nexus_trans()`.

Keep the old one intact, and use the new way for all new functionality. The new approach can be used in both Nexus and Laravel environments.
