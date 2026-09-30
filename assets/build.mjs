import * as esbuild from 'esbuild';

const watch = process.argv.includes('--watch');

const common = {
  bundle: true,
  minify: !watch,
  sourcemap: watch,
  target: ['es2020'],
  logLevel: 'info',
};

// worker.ts is never served as its own file: its bundled source is
// embedded into widget.ts as a string, and widget.ts runs it from a
// Blob URL at runtime (see assets/src/widget.ts). This keeps the whole
// widget to a single request.
async function buildWorkerSource() {
  const result = await esbuild.build({
    ...common,
    entryPoints: ['assets/src/worker.ts'],
    write: false,
    format: 'iife',
  });
  return result.outputFiles[0].text;
}

async function build() {
  const workerSource = await buildWorkerSource();

  const widgetOptions = {
    ...common,
    entryPoints: ['assets/src/widget.ts'],
    outdir: 'assets/build',
    format: 'iife',
    define: {
      NXC_WORKER_SOURCE: JSON.stringify(workerSource),
    },
  };

  const adminOptions = {
    ...common,
    entryPoints: ['assets/src/admin.ts'],
    outdir: 'assets/build',
    format: 'iife',
  };

  if (watch) {
    const [widgetCtx, adminCtx] = await Promise.all([
      esbuild.context(widgetOptions),
      esbuild.context(adminOptions),
    ]);
    await Promise.all([widgetCtx.watch(), adminCtx.watch()]);
  } else {
    await Promise.all([esbuild.build(widgetOptions), esbuild.build(adminOptions)]);
  }
}

await build();
