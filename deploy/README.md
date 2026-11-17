# VM background services

The units target `/var/www/zchpc-biometrics/zchpc-dashboard`, run as `user:www-data`, and restart after failure or reboot. Install them with root privileges after verifying the local build and database backup:

```sh
install -m 644 deploy/zchpc-scheduler.service deploy/zchpc-queue.service /etc/systemd/system/
systemctl daemon-reload
systemctl enable --now zchpc-scheduler zchpc-queue
```

Retire existing manually started `artisan schedule:work` processes before enabling the scheduler. Keep exactly one scheduler and one worker. During future deployments, run migrations, rebuild cached configuration, then restart both services after current jobs finish. The queue reservation in `config/queue.php` must exceed the 900-second worker timeout. Check `systemctl status zchpc-scheduler zchpc-queue` and `journalctl -u zchpc-scheduler -u zchpc-queue`.

Monitoring and sync retries run independently in the background. A failed access sync cannot delay the health check schedule. Pending changes become immediately eligible when a previously unavailable device answers an authenticated probe. Batch retries are bounded and resume on subsequent runs.

Online status requires a successful authenticated device read. Services cannot repair a power outage, broken cable, VLAN/firewall rule, or a disabled terminal web service. Connection refused indicates a rejecting host or firewall; a timeout indicates an unreachable or silently filtered path. Do not hide either condition by forcing an online flag.

# Personnel changes

User removal archives the local identity and keeps its employee number reserved. Controller deletion is queued and confirmed by searching the controller after deletion. Offline devices may retain access until they reconnect. Archived identities cannot be recreated by device imports; audit history is retained. Pending removals remain visible under Controller synchronization.

For reviewed name corrections, prepare a private CSV with exactly `employee_no,expected_name,name`. Run `php artisan personnel:reconcile-names /private/path/names.csv` to preview, then repeat with `--apply`. The command changes existing names only, validates the whole batch transactionally, refuses unexpected names and unknown IDs, and is safe to repeat. Name-only device writes do not grant access or change credentials. Keep personnel files and backups outside Git.
