# tuya_switch.py v:1.0.0 d:2026-10-23 i:evs
# Tænder / slukker et Tuya-relæ direkte på hjemmenettet (ingen cloud) - kaldes af ladestyring.lib.php.
# Input (stdin, JSON): {"id": "...", "ip": "192.168.1.50" eller "Auto", "key": "...", "ver": "3.3", "dp": 1, "on": true / false / null (kun status)}
# Output (JSON):       {"ok": true, "on": true}  eller  {"ok": false, "error": "..."}
import json, sys

def main():
    try:
        import tinytuya
    except ImportError:
        return {"ok": False, "error": "tinytuya mangler - kør: pip install tinytuya"}
    a = json.loads(sys.stdin.read() or "{}")
    for k in ("id", "key"):
        if not a.get(k):
            return {"ok": False, "error": "mangler " + k}
    d = tinytuya.OutletDevice(a["id"], a.get("ip") or "Auto", a["key"], version=float(a.get("ver") or 3.3))
    d.set_socketTimeout(6)
    d.set_socketRetryLimit(2)
    dp = int(a.get("dp") or 1)
    if a.get("on") is None:
        r = d.status()
    else:
        r = d.set_status(bool(a["on"]), switch=dp)
        if isinstance(r, dict) and "dps" not in r and "Error" not in r:
            r = d.status()                      # (nogle enheder svarer ikke med dps på en kommando)
    if not isinstance(r, dict):
        return {"ok": False, "error": "intet svar fra enheden"}
    if "Error" in r:
        return {"ok": False, "error": "%s (%s)" % (r.get("Error"), r.get("Err", ""))}
    dps = r.get("dps", {})
    state = dps.get(str(dp))
    if a.get("on") is not None and state is not None and bool(state) != bool(a["on"]):
        return {"ok": False, "error": "relæet svarer %s" % ("tændt" if state else "slukket")}
    return {"ok": True, "on": state}

try:
    print(json.dumps(main()))
except Exception as e:
    print(json.dumps({"ok": False, "error": str(e)}))
