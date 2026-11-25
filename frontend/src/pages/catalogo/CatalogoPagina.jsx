import { useCallback, useMemo } from 'react'

import { useSearchParams } from 'react-router-dom'

import { SearchX } from 'lucide-react'

import { comercioApi } from '../../api/comercioApi'

import FiltrosCatalogo from '../../components/comercio/FiltrosCatalogo'

import ProductoTarjeta from '../../components/comercio/ProductoTarjeta'

import Alerta from '../../components/ui/Alerta'

import PaginaListado from '../../components/plantillas/PaginaListado'

import { useAgregarAlCarrito } from '../../hooks/useAgregarAlCarrito'

import { useCargaApi } from '../../hooks/useCargaApi'

import './CatalogoPagina.css'

const CLAVES = ['q', 'categoria', 'comunidad', 'precio_min', 'precio_max', 'con_stock', 'orden', 'pagina']
const MENSAJE_RANGO_PRECIO = 'El precio «hasta» no puede ser menor que el precio «desde».'

export default function CatalogoPagina() {
  const [parametros, setParametros] = useSearchParams()
  const valores = useMemo(() => Object.fromEntries(CLAVES.map((c) => [c, parametros.get(c) || ''])), [parametros])
  const clave = parametros.toString()

  // Un rango de precios invertido se avisa en el cliente y no se consulta (ni se muestran resultados viejos).
  const rangoInvalido = valores.precio_min !== '' && valores.precio_max !== '' && Number(valores.precio_max) < Number(valores.precio_min)
  const catalogo = useCargaApi(() => (rangoInvalido
    ? Promise.reject({ mensaje: MENSAJE_RANGO_PRECIO })
    : comercioApi.catalogo(Object.fromEntries(parametros))), [clave])
  const opciones = useCargaApi(() => comercioApi.filtrosCatalogo(), [])
  const { agregar, estado, puedeComprar } = useAgregarAlCarrito()

  const cambiarFiltros = useCallback((cambios) => {
    setParametros((actuales) => {
      const nuevos = new URLSearchParams(actuales)
      Object.entries(cambios).forEach(([c, v]) => (v ? nuevos.set(c, v) : nuevos.delete(c)))
      if (!('pagina' in cambios)) nuevos.delete('pagina')
      return nuevos
    })
  }, [setParametros])

  const activos = CLAVES.filter((campo) => campo !== 'pagina' && valores[campo]).length
  const total = catalogo.datos?.paginacion.total ?? 0
  const productos = catalogo.error ? [] : (catalogo.datos?.productos ?? [])

  return (
    <div className="catalogo">
      <PaginaListado
        titulo="Catálogo"
        descripcion="Productos de las comunidades afiliadas a FEDER."
        etiquetaFiltros="Filtrar productos"
        alLimpiarFiltros={() => setParametros({})}
        filtrosActivos={activos}
        actualizando={catalogo.actualizando}
        alReintentar={rangoInvalido ? null : () => { catalogo.recargar(); if (opciones.error) opciones.recargar() }}
        filtros={opciones.datos && (
          <FiltrosCatalogo valores={valores} opciones={opciones.datos} alCambiar={cambiarFiltros} rangoInvalido={rangoInvalido} />
        )}
        cargando={catalogo.cargando && !catalogo.datos}
        error={catalogo.error || opciones.error}
        resumen={catalogo.datos && !catalogo.error ? `${total} ${total === 1 ? 'producto encontrado' : 'productos encontrados'}` : null}
        vacio={productos.length === 0 && !catalogo.error ? {
          titulo: activos ? 'No hay productos con esos filtros' : 'Todavía no hay productos en el catálogo',
          mensaje: activos ? 'Prueba quitando alguno o ampliando el rango de precios.' : undefined,
          accion: activos ? <button type="button" className="boton boton--secundario" onClick={() => setParametros({})}>Quitar filtros</button> : null,
          icono: SearchX,
        } : null}
        paginacion={productos.length ? {
          pagina: catalogo.datos.paginacion.pagina,
          paginas: catalogo.datos.paginacion.paginas,
          alCambiar: (pagina) => cambiarFiltros({ pagina: String(pagina) }),
        } : null}
      >
        {estado.mensaje && <Alerta tipo={estado.tipo}>{estado.mensaje}</Alerta>}

        <div className="catalogo__rejilla">
          {productos.map((p) => (
            <ProductoTarjeta key={p.id} producto={p} consultaCatalogo={clave} puedeComprar={puedeComprar}
              enviando={estado.enviando && estado.productoId === p.id} alAgregar={(producto) => agregar(producto, 1)} />
          ))}
        </div>
      </PaginaListado>
    </div>
  )
}
