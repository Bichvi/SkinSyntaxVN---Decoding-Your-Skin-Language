# SYNA atlas provenance

Created with the built-in image generation tool, using the user's attached LUNA
character sheet as the visual reference. No external image API key was used.
Final project asset: `luna-atlas.png`. Original input is a reference, not a runnable
puppet. Final atlas includes an alpha channel; the first checkerboard-baked attempt
was discarded from the project. Runtime Canvas crops/draws individual components.

## Generation prompt

Use case: stylized-concept. Asset type: a single production 2D puppet texture atlas, for a speaking mascot in a web animation. Reference image is the user's SkinSyntax cream cat LUNA character sheet: preserve its cream fur, large head, green headset, tiny green leaf sprout, dark forest-green hoodie, cute rounded proportions, soft illustrated toy-like shading. This is NOT a new character. Create ONE atlas on a genuinely TRANSPARENT background, no white or checkerboard baked in. EXACT layout 1536 by 1024 pixels, THREE equal columns and TWO equal rows, six invisible 512x512 cells. Each isolated component centered in its own cell, with 35px empty margin, nothing crosses cells. NO labels, no text, no grid. Top-left cell: ONLY oversized head with ears, headset and leaf sprout, front facing, rosy cheeks, pink triangular nose and small whiskers. LEAVE EYE AREAS AND MOUTH AREA BLANK cream fur so we can overlay animated eyes and mouth in code. Top-middle cell: ONLY torso in dark green hoodie with tiny green leaf emblem (no lettering), short cream legs and green shoes, front facing, NO head, NO arms, NO tail. Top-right cell: ONLY left arm, green short sleeve at top, cream rounded paw at bottom, resting straight downward, generous rounded shoulder overlap. Bottom-left cell: ONLY right arm, green short sleeve at top, cream rounded paw at bottom, same scale and pose as left arm mirrored. Bottom-middle cell: ONLY curved cream cat tail, pointing upward to the right, fluffy smooth silhouette. Bottom-right cell: ONLY a PAIR of big open expressive dark green cat eyes, highlights and subtle upper lashes matching the reference, on transparency, spaced symmetrically horizontally, no face around them. Each component is a reusable sprite for rigging, consistent softly shaded edges and lighting upper left, polished premium cute look, not a technical diagram. No duplicate whole cats. No props, no bubbles, no text, no background.

## Corrective edit prompt

Use case: background-extraction. Edit target: the supplied 1536x1024 puppet atlas.
Change ONLY the background. Remove every grey checkerboard square: it is incorrectly
baked into the image. Return an RGBA PNG with genuine transparent alpha everywhere
outside the six components, including holes and spaces between the eyes. Preserve
every component's position, dimensions, colors and all details pixel-aligned to
the original, no rearranging or resizing or extra pieces. Do not illustrate
transparency as checkerboard. If genuine alpha output is unavailable, instead use
a completely uniform pure saturated MAGENTA (#FF00FF) background, with no texture
or shadow on background, so the application's sprite importer can key it. No
checkerboard, no text.

The corrected output has genuine alpha; no chroma-key conversion was needed. A
later Syna revision requested a short visible neck and rounded Centella leaves;
the image tool quota was unavailable during this pass, so those two details are
currently rendered as separate Canvas layers in `renderer.js` and the original
atlas remains untouched.

## Current v4 asset (2026-09-14)

Project asset: `syna-key-atlas-v4.png` (1536x1024, RGB magenta key), generated with
the built-in image tool from the old atlas. This is the active asset; the older
Luna PNG remains only as history. The importer in `character.js` removes the
magenta background once. No external API key or paid image API was used.

First prompt: “Use case: precise-object-edit. Edit target: the supplied transparent
RGBA 1536x1024 cat puppet atlas. Change ONLY ONE thing: completely REMOVE the
pointed two-leaf sprout and both its stems from the top of the head. Leave that
space genuinely transparent. Preserve the whole green headset band and both cat
ears, perfectly intact. All seven components (head, body, two arms, tail, two eyes)
must stay at the EXACT original pixel positions and sizes in the 1536x1024 canvas.
Preserve cream fur, cheeks, nose, whiskers, blank eye/mouth areas on the head,
colors, shading and genuine transparency. Do NOT add any new plant, neck, eyes,
mouth or full character; no text, no checkerboard, no opaque background, no halos.
This is a precision atlas edit for an existing animation rig. The plant will be
drawn as a separate animated layer in code.”

The output and a transparency-correction attempt were both RGB with baked
checkerboards. They were inspected and excluded from the project. Final prompt:

“Use case: precise-object-edit. Change ONLY THE BACKGROUND of this 1536x1024 puppet
atlas to completely flat pure saturated MAGENTA #FF00FF. Remove ALL checkerboard
texture, without any shadows, halos or gradient on the MAGENTA background. All 7
sprites must remain exactly pixel-aligned at original positions and dimensions:
cream head without plants, hoodie body, arms, tail, eyes. Retain original colors,
face identity, shading, ears and intact headset, existing leaf emblem on chest.
Head has NO eyes or mouth and NO sprout/plant. Empty regions and holes between
sprite pieces MUST be a single flat RGB 255,0,255. Do not simulate transparency.
This is a color-key asset for a Canvas animation renderer. No text, no checkerboard.”

The Centella layer is drawn natively in Canvas: broad scalloped fan, basal notch,
radiating/branching veins and a connected stalk, based on the user's supplied
leaf-shape reference. The stock preview itself is not embedded. Expressions,
neck/arm deformation, props and stickers are also native Canvas work.

Narration is local Piper vi_VN-vais1000-medium with a 2.5-semitone pitch increase,
duration compensation and a peak limiter. The generator measures each processed
segment for its caption times. Sources are sample script text, no voice cloning.

## Presentation v5/v6

`syna-presentation-v5.wav/json` were produced by the standalone generator with
`--presentation --pitch-semitones 2.5`, using the existing local Piper container.
38.9677 seconds, eight processed sentence cues. The atlas remains v4. Product
bottle, separate board, pointer and connected sleeve/shoulder layers are native
Canvas graphics. The product, 50 ml size and 199,000 VND price are labelled design
fixtures, not a real SKU, formulation, promotion or clinical claim.

General ingredient-purpose copy is deliberately qualified with “hỗ trợ”. These
studies support the illustrative summaries, not efficacy of the fictional gel:

- Rau má / soothing: [human study of Centella-containing formulations](https://pubmed.ncbi.nlm.nih.gov/27168678/).
- Glycerin / hydration: [randomized crossover study separating glycerol and petrolatum effects](https://pubmed.ncbi.nlm.nih.gov/31532576/).
- Panthenol / skin barrier support: [controlled human dexpanthenol formulation study](https://pubmed.ncbi.nlm.nih.gov/10965426/).

Effects depend on the actual concentration, vehicle and finished formulation.
Before real catalog integration, staff must confirm INCI, product claims and
current price from the product record; the demo must not infer these from names.
