#!/usr/bin/env python3
"""
Strict YAML check for openapi.yaml.

WHY THIS EXISTS, IN ONE PARAGRAPH

The PHP validator (check-openapi.php) resolves $refs and counts paths, and it said
"RESULT: OK" for a document that the browser refused to render:

    YAMLException: duplicated mapping key (2325:5)

Because the two components that read this file use different parsers, and only one of
them cares: PHP's yaml extension silently keeps the LAST duplicate key (so the $refs
still resolve and every count still adds up), while the JavaScript parser inside
swagger-ui throws and the whole document is rejected. A duplicate key is therefore
invisible to the PHP check and fatal in the browser -- the worst combination.

PyYAML's default loader also keeps the last key quietly, so this script installs a
constructor that raises on a repeated key in the same mapping. It is deliberately the
same rule js-yaml applies.

    python3 bin/check-openapi-strict.py openapi.yaml

Exit 0 = the browser will parse it. Exit 1 = it will not, with the line and column.
"""
import sys
import yaml


class StrictLoader(yaml.SafeLoader):
    """SafeLoader that refuses a repeated key in one mapping (the js-yaml rule)."""


def _strict_mapping(loader, node, deep=False):
    loader.flatten_mapping(node)
    mapping = {}
    for key_node, value_node in node.value:
        key = loader.construct_object(key_node, deep=deep)
        if key in mapping:
            raise yaml.constructor.ConstructorError(
                "while constructing a mapping",
                node.start_mark,
                "found duplicate key %r" % (key,),
                key_node.start_mark,
            )
        mapping[key] = loader.construct_object(value_node, deep=deep)
    return mapping


StrictLoader.add_constructor(
    yaml.resolver.BaseResolver.DEFAULT_MAPPING_TAG, _strict_mapping
)


def main(argv):
    if len(argv) < 2:
        print("usage: check-openapi-strict.py <openapi.yaml>")
        return 2

    path = argv[1]
    try:
        with open(path, "r", encoding="utf-8") as handle:
            doc = yaml.load(handle, Loader=StrictLoader)  # noqa: S506 - SafeLoader subclass
    except yaml.constructor.ConstructorError as exc:
        mark = exc.problem_mark
        where = "%s:%s:%s" % (path, mark.line + 1, mark.column + 1) if mark else path
        print("DUPLICATE KEY  " + where)
        print("  " + str(exc.problem))
        print("  js-yaml (swagger-ui) rejects the whole document on this, so /docs")
        print("  would show 'Reference unavailable'. Rename the later key.")
        return 1
    except yaml.YAMLError as exc:
        print("YAML ERROR  " + path)
        print("  " + str(exc))
        return 1

    if not isinstance(doc, dict):
        print("NOT A MAPPING  " + path)
        return 1

    paths = doc.get("paths") or {}
    operations = 0
    for item in paths.values():
        if isinstance(item, dict):
            operations += len([m for m in item if m in
                               ("get", "put", "post", "delete", "patch", "head", "options", "trace")])
    schemas = ((doc.get("components") or {}).get("schemas") or {})
    print("STRICT OK  %s" % path)
    print("  openapi    %s" % doc.get("openapi"))
    print("  version    %s" % ((doc.get("info") or {}).get("version")))
    print("  paths      %d" % len(paths))
    print("  operations %d" % operations)
    print("  schemas    %d" % len(schemas))
    print("  no mapping has a repeated key (the rule swagger-ui enforces)")
    return 0


if __name__ == "__main__":
    sys.exit(main(sys.argv))
