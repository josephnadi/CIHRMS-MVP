# Sound pack — where to source production audio

This guide answers: *we want real doorbell/train/cash-register sounds in the
cinematic pack — what do we actually download, and what licence covers a
government deployment?*

CIHRMS ships with synthesised approximations so the system never breaks if
no files are present. Replace them at any time by dropping audio into
`public/sounds/cinematic/`. The file name is the event-key contract — see
`public/sounds/README.md`.

---

## Licence requirements (read this first)

CIHRMS is a government system serving the Chartered Institute of Human
Resource Management, Ghana. Any audio shipped in the binary must satisfy:

1. **Commercial / government use explicitly permitted** — not "free for
   personal use", not "non-commercial" only.
2. **Redistribution permitted** — the file ships inside the application.
3. **No attribution-in-product requirement** — credits-in-app are
   acceptable; per-screen attribution is not.

The licences that satisfy all three:

| Licence             | Verdict | Notes |
| ------------------- | ------- | ----- |
| **CC0 / Public Domain** | ✅      | Best. No restrictions. Credit voluntary. |
| **CC-BY 4.0**       | ✅      | OK if a credits page is included. |
| **CC-BY-SA 4.0**    | ⚠️      | Permitted, but the share-alike obligation propagates — derivative works must use the same licence. Usually a no for proprietary code. |
| **CC-BY-NC 4.0**    | ❌      | Non-commercial; government deployment is commercial use. |
| **"Royalty-free"** (commercial libraries) | ✅ | OK with a paid licence; keep the receipt. |
| Proprietary, no licence file | ❌ | Never. |

If in doubt, **download from CC0 sources only** and document the URL +
licence text in a `CREDITS.md` at deploy time.

---

## Recommended CC0 sources

These libraries host CC0 / public-domain audio that satisfies all three
licence requirements above. Tested 2026-05; URLs and licences change —
re-verify each file's licence on its actual download page before
shipping.

### Freesound.org (filter to CC0)
- The largest community audio library. Filter UI: *Licence → Creative
  Commons 0*. Many users tag their uploads CC-BY by default; the CC0
  filter is essential.
- Search terms that produce good results:
  - "doorbell ding dong" — multiple CC0 residential doorbells
  - "cash register chime"
  - "train horn diesel"
  - "wood knock single"
  - "glass clink"

### Pixabay Sound Effects
- All Pixabay audio is licenced under the **Pixabay licence**, which
  permits commercial use, redistribution, and modification without
  attribution. Treat as effectively CC0.
- Direct downloads, no account needed. Bulk-downloadable.

### Mixkit
- Free SFX library with a permissive licence equivalent to CC0 for the
  free tier. Read the Mixkit Sound Effects Licence before bulk download.

### Sonniss GDC bundles
- Sonniss publishes a free SFX bundle each GDC. **Licence: royalty-free,
  commercial use OK, redistribution forbidden as a bundle but individual
  use inside a product is fine.**
- High-quality, professionally recorded — preferable for the doorbell
  and train-horn slots if production polish matters.

---

## Suggested files per event (cinematic pack)

| event-key          | file name in repo            | suggested source / search                |
| ------------------ | ---------------------------- | ---------------------------------------- |
| `notification`     | `notification.mp3`           | Freesound CC0: "doorbell ding dong"      |
| `success`          | `success.mp3`                | Freesound CC0: "bell chime single"       |
| `error`            | `error.mp3`                  | Freesound CC0: "wood knock low"          |
| `warning`          | `warning.mp3`                | Pixabay: "train horn distant"            |
| `event.created`    | `event-created.mp3`          | Freesound CC0: "calendar bell"           |
| `assigned.you`     | `assigned.mp3`               | Freesound CC0: "email pop"               |
| `task.completed`   | `task-completed.mp3`         | Freesound CC0: "cash register chime"     |
| `message`          | `message.mp3`                | Freesound CC0: "phone ring short"        |
| `announcement`     | `announcement.mp3`           | Freesound CC0: "station bell chime"      |
| `submit`           | `submit.mp3`                 | Freesound CC0: "wood knock high"         |
| `invalid`          | `invalid.mp3`                | Freesound CC0: "buzzer error"            |
| `approved`         | `approved.mp3`               | Freesound CC0: "bell chime bright"       |
| `rejected`         | `rejected.mp3`               | Freesound CC0: "buzzer low"              |
| `welcome`          | `welcome.mp3`                | Freesound CC0: "station bell rising"     |
| `goodbye`          | `goodbye.mp3`                | Pixabay: "train horn low slow"           |

## File preparation checklist

Before dropping a file into `public/sounds/cinematic/`:

- [ ] Confirm licence on the source page (screenshot for the audit trail).
- [ ] Trim to ≤ 1.5 seconds for short events (notification, error,
      success) or ≤ 2.5 seconds for long ones (train horn, station
      bell). WCAG 1.4.2 caps auto-play at 3 seconds.
- [ ] Normalise loudness to **-14 LUFS** (broadcast standard for UI
      audio). Audacity → Analyze → Loudness Normalisation.
- [ ] Export as **MP3 96 kbps mono** — small file, indistinguishable
      from higher bitrate at this duration. Stereo not needed for UI.
- [ ] Fade-out the last 30 ms to prevent click on stop.
- [ ] Verify with the SoundToggle "Preview" button before merging.

## Audit trail

When new files are added to the repo, add a row to this table so legal
can verify the chain of custody:

| File                  | Source URL | Licence | Downloaded by | Date       |
| --------------------- | ---------- | ------- | ------------- | ---------- |
| _(none yet — synth only)_ |        |         |               |            |

## Hiring a sound designer

If the synth + free-library combo doesn't hit the bar, the next step is
custom-recorded assets:

- Budget: **GHS 2,500 – 6,500** for a 15-asset cinematic pack from a
  Ghanaian audio post-production house.
- Deliverable: 15 × WAV (mastered, -14 LUFS, mono, 48 kHz, ≤ 3 s), plus
  an MP3 96 kbps export of each.
- IP: full work-for-hire assignment to CIHRM in writing.
- Brief: "Public-service HR system, Ghana. Tone: warm, dignified,
  unobtrusive. Doorbell should evoke a well-kept embassy, not a domestic
  flat. Train horn should be distant — not aggressive."

## Synth fallback (what ships today)

The `cinematic-synth.js` module ships physical-modelling approximations
so the UX is complete without any audio assets. Notes on quality:

- **Bell / doorbell** — additive synthesis with measured inharmonic
  partials (hum, prime, tierce, quint, nominal, deciem, upper octave,
  upper twelfth). Recognisably a bell, but lacks the body of a real
  recording. ★★★☆☆
- **Train horn** — 3-note sawtooth chord with vibrato, low-pass for
  "distant" variant. Convincing for warning use. ★★★★☆
- **Wood knock** — band-pass noise burst. Indistinguishable from a
  real knock at low volume. ★★★★★
- **Cash register** — layered click + bell + drawer slide. Reads as
  ka-ching but lacks the chrome jangle of a real till. ★★★☆☆
- **Glass clink** — high inharmonic partials. Very convincing. ★★★★★
- **Phone ring** — US 440+480 Hz dual-tone, classic and accurate. ★★★★☆
- **Buzzer / alarm / email pop** — workmanlike. ★★★☆☆

The 3-star items are the priority replacements when real files arrive.
