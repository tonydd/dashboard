type Route =  { path: string, component: string, namedParameters?: string[] };

export const parameterizedRoutes: Route[] = [
    {
        path: '/recipe/view/(\\d+)',
        component: 'Recipe/View.vue',
        namedParameters: ['recipeId'],
    }
];