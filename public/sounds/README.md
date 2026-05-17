# Sound asset drop-in

This directory is read at runtime by `resources/js/composables/useSound.js`.

When a file exists at `/sounds/<pack>/<event-key>.mp3`, useSound plays the
real audio file instead of the Web Audio synth fallback. Drop a file in →
refresh the browser → next event of that key uses the file.

## Filename contract

```
public/sounds/<pack>/<event-key>.<ext>
```

- `<pack>`         — `musical` or `cinematic` (must match `useSound`'s
                     `activePack`).
- `<event-key>`    — the string passed to `play(key)`. See list below.
- `<ext>`          — `.mp3` is checked first; `.ogg` and `.wav` work too
                     if you serve them with the right Content-Type.

Sounds longer than ~3 seconds violate WCAG 1.4.2 (no auto-play of long
audio). Keep clips short. ADSR envelope handled by the browser, not us.

## Cinematic pack — drop-in keys

| event-key          | suggested sound                              |
| ------------------ | -------------------------------------------- |
| `notification`     | residential doorbell (ding-dong)             |
| `success`          | bright bell chime / glass clink              |
| `error`            | wooden knock, low                            |
| `warning`          | distant train horn                           |
| `event.created`    | calendar bell                                |
| `assigned.you`     | email pop / "you've got mail"                |
| `task.completed`   | cash register ka-ching                       |
| `message`          | phone ring (short, 1 burst)                  |
| `announcement`     | station bell / airport chime                 |
| `submit`           | wood knock, high                             |
| `invalid`          | buzzer                                       |
| `approved`         | bell, medium                                 |
| `rejected`         | buzzer, low                                  |
| `welcome`          | station bell, rising                         |
| `goodbye`          | train horn, low and slow                     |

## Where to source files

See `docs/sound_pack_sources.md` for vetted CC0 / public-domain sources
and recommended licences for production use. Do not ship copyrighted
audio without written permission — even if a source claims it is "free
for non-commercial use", CIHRMS is a government system and the licence
must explicitly permit government / commercial deployment.

## Verification

After dropping files, open the SoundToggle popover → "Preview" — every
button should now sound like the dropped file rather than the synth.
The browser's network tab will show one `fetch` per first-play; the file
is cached as a decoded AudioBuffer thereafter.

## Why .gitkeep

Empty subdirectories aren't tracked by Git. The `.gitkeep` placeholder
ensures the directory exists in fresh clones so first-run `useSound`
calls don't 404 on the directory lookup.
