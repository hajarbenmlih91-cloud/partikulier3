.PHONY: lint package clean

lint:
	@bash scripts/lint.sh

package:
	@bash scripts/package.sh dist

clean:
	@rm -rf dist
