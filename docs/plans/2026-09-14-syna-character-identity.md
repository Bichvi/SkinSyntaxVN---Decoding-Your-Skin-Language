# Character Identity — Syna, SkinSyntax mascot

This is the visual source of truth for every Syna pose, preview and future
asset. A pose may change the hands, expression or held product; it must not
redesign the character.

## Locked identity

- Premium cute 3D chibi cat; oversized nearly round head is about 55–60% of the
  visual height, with a compact plush body, short arms, short legs and a small
  curved tail. Never make Syna tall, slim or anatomically realistic.
- Warm ivory/cream fur, subtle blended muzzle, low and wide oversized dark
  emerald eyes with deep-green gradient, black pupils and white catchlights;
  pastel-pink triangular nose, tiny w-mouth, blush and three fine whiskers per
  cheek. Blinking and winking must not change the base proportions.
- Soft rounded cream ears with pale blush inner ears and sage-gray inner shadow.
- Two Centella asiatica / gotu kola / rau má leaves on curved stems at the exact
  center-top: one large round scalloped fan leaf with visible radial veins and
  one smaller leaf behind/to the side. It must never become a clover, shamrock,
  generic flower, broccoli or antenna.
- Large dark forest-green padded headset following the crown behind the sprout;
  circular earcups, mint glowing `S` on each visible cup, slim boom and small
  microphone capsule.
- Roomy forest-green hoodie, short sleeves, two cream drawstrings, and a
  **Centella/rau má leaf emblem on the chest**. The chest mark is a botanical
  rau má leaf with scalloped edge and veins, not the generic two-pointed sprout
  and not text. Cream paws may show dark-green paw pads; feet are small rounded
  green coverings; tail is short and cream.

## Rendering guardrails

Use warm ivory, forest green, emerald, mint and blush with soft studio lighting,
matte plush/vinyl materials and gentle shadows. Avoid realistic fur strands,
thin bodies, small eyes, changed eye color, missing headset, random accessories,
extra leaves, logo substitutions or inconsistent proportions.

## Current implementation

`model.js` and `anatomy.js` implement the locked silhouette; `face-paint.js`
keeps the eye aperture round during blink and gives the eyes their emerald
gradient/catchlights. The 3D demo's action atlas remains a reference for future
rigged poses; it is not a bitmap replacement for the model.
