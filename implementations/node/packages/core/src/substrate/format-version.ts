/**
 * The data-format version every export manifest carries.
 *
 * The spec is explicit that this always states the *current* spec version
 * (`datenformat.md`: „`formatVersion` trägt stets die aktuelle Spec-Version"). It sat at 0.4 while
 * the schema, the pack modules and the parameter contract had long moved to 0.6 — an export that
 * understated its own format, which is the one field a reading system uses to decide whether it
 * can read the file at all.
 *
 * It lives here, in one place per language, and a test asserts it matches the version in the
 * schema's `$id`. Bumping the schema without bumping this now turns a build red instead of
 * shipping a mislabelled export.
 */
export const FORMAT_VERSION = '0.7';
