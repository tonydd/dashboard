import { defineAsyncComponent } from 'vue';

const components = import.meta.glob('@/components/templates/**/*.vue');

export function loadComponentByName(name: string) {
    const match = Object.keys(components).find(path => path.endsWith(`/${name}.vue`));

    if (!match) {
        throw new Error(`Composant "${name}" introuvable dans components`);
    }

    return defineAsyncComponent(components[match]);
}