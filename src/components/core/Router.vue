<template>
  <component :is="asyncComponent" :parameters="bag" />
</template>

<script setup lang="ts">
import {type Component, defineAsyncComponent, onBeforeMount, provide, ref, Ref} from "vue";
import {parameterizedRoutes} from '@/router/routes';

const components = import.meta.glob('@/components/templates/**/*.vue') as Record<string, () => Promise<{ default: Component }>>;

function checkParameterizedRoute (path: string): { component: () => Promise<{ default: Component }>, bag: Record<string, any> } | null {

  for (let route of parameterizedRoutes) {
    // Replace the placeholder with a regex pattern for numbers
    let regexPattern = route.path.replace(/\(\\d\+\)/g, '(\\d+)');
    regexPattern = regexPattern.replace(/\//g, '\\/');

    // Create a regex that matches the path structure
    const regex = new RegExp(`^${regexPattern}$`, 'i');
    const regexExec = regex.exec(path);
    if (!regexExec) {
      continue;
    }

    let matches = regexExec.splice(1);
    if (matches === null) {
      continue;
    }

    let bag = {};
    if (route.namedParameters?.length === matches.length) {
      for (const key in route.namedParameters) {
        const parameterName = route.namedParameters[key];
        bag[parameterName] = matches[key];
      }
    } else {
      for (const key in matches) {
        bag[key] = matches[key];
      }
    }

    console.log('search ', '/src/components/templates/' + route.component, components);
    return {
      component: components['/src/components/templates/' + route.component],
      bag
    }
  }

  return null;
}

function checkPath(path: string): () => Promise<{ default: Component }> {
  const list = Object.keys(components)

  let elements: string[] = path.split('/');
  elements = elements.map((element: string): string => element[0].toUpperCase() + element.substring(1));
  path = elements.join('/');

  const match = list.find(currentPath => currentPath.endsWith(`/${path}.vue`));
  console.log('Searched for endsWith', path, 'in', list, 'matched', match);

  if (!match) {
    throw new Error(`"${path}" : Route introuvable`);
  }

  return components[match];
}
function loadComponentByPath(path: string) {
  return defineAsyncComponent(checkPath(path));
}

const HOME: string = 'Home',
    asyncComponent = ref(defineAsyncComponent(checkPath(HOME))),
    bag: Ref<Record<string, any>> = ref({});

function loadFromWindowPath(): void {
  let currentUrl: string = window.location.pathname.substring(1);
  if (currentUrl === '') {
    currentUrl = HOME;
  } else {
    const search = window.location.search.substring(1);
    const rawJson = '{"' + decodeURI(search).replace(/"/g, '\\"').replace(/&/g, '","').replace(/=/g,'":"') + '"}';
    bag.value = rawJson !== '{""}'
        ? JSON.parse(rawJson)
        : {}
    ;
  }

  try {
    const parameterizedRoute = checkParameterizedRoute(window.location.pathname);
    if (parameterizedRoute) {
      bag.value = {...bag.value, ...parameterizedRoute.bag};
      asyncComponent.value = defineAsyncComponent(parameterizedRoute.component);
    } else {
      asyncComponent.value = loadComponentByPath(currentUrl);
    }
  } catch (error) {
    console.log(error);
  }
}

onBeforeMount(loadFromWindowPath);

function navigateTo(path: string, parameters: Record<string, any> = {}) {
  try {
    const parameterizedRoute = checkParameterizedRoute(window.location.pathname);
    if (parameterizedRoute) {
      bag.value = {...parameters, ...parameterizedRoute.bag};
      asyncComponent.value = defineAsyncComponent(parameterizedRoute.component);
    } else {
      const async = checkPath(path);
      asyncComponent.value = defineAsyncComponent(async);
      bag.value = parameters;
    }

    let getParameters: string = '';
    for (const key in parameters) {
      const param = parameters[key];
      if (typeof param === 'string' || typeof param === 'number') {
        if (getParameters === '') {
          getParameters = getParameters + '?';
        } else {
          getParameters = getParameters + '&';
        }

        getParameters = getParameters + encodeURI(`${key}=${param}`);
      }
    }

    const newPath = '/' + path + getParameters;
    window.history.pushState(parameters, null, newPath);
  } catch (error) {
    console.log(error);
  }
}

if ('onpopstate' in window) {
  window.addEventListener('popstate', () => {
    loadFromWindowPath();
  });
}


provide('navigateTo', navigateTo);
</script>