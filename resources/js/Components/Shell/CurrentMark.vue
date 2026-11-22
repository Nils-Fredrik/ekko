<template>
    <!-- The dot beside the chosen item in a menu, read out as «Valgt nå». -->
    <span class="flex flex-none items-center">
        <span class="size-1.5 rounded-full bg-primary" aria-hidden="true"></span>
        <span class="sr-only">{{ $t('workspace.palette.current') }}</span>
    </span>
</template>
