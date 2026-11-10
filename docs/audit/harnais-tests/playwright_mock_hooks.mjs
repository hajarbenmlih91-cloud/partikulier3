export async function resolve(specifier, context, nextResolve) {
  if (specifier === 'playwright') {
    return {
      shortCircuit: true,
      url: new URL('./playwright_mock.mjs', import.meta.url).href,
    };
  }
  return nextResolve(specifier, context);
}
